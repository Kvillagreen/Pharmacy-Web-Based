#!/usr/bin/env python3
"""Generate a terminal evidence report for the white-box alpha JUnit XML.

Usage examples:
  python Backend/scripts/generate_whitebox_alpha_terminal_report.py
  python Backend/scripts/generate_whitebox_alpha_terminal_report.py --from TC-001 --to TC-050
  python Backend/scripts/generate_whitebox_alpha_terminal_report.py --junit outputs/backend-whitebox/latest-junit.xml
"""

from __future__ import annotations

import argparse
import json
import re
import xml.etree.ElementTree as ET
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_JUNIT = ROOT / "outputs" / "backend-whitebox" / "latest-junit.xml"
DEFAULT_MAP = ROOT / "Backend" / "tests" / "qase-workflow-map.json"
DEFAULT_OUTPUT = ROOT / "outputs" / "backend-whitebox" / "whitebox-alpha-terminal-evidence.txt"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Generate terminal evidence from the white-box alpha JUnit XML.")
    parser.add_argument("--junit", default=str(DEFAULT_JUNIT), help="Path to the JUnit XML file.")
    parser.add_argument("--map", default=str(DEFAULT_MAP), help="Path to the QASE workflow map JSON.")
    parser.add_argument("--output", default=str(DEFAULT_OUTPUT), help="Where to save the terminal evidence report.")
    parser.add_argument("--all", action="store_true", help="Include both passing and failing cases in the report; otherwise only failures/errors are shown.")
    parser.add_argument("--from", dest="from_case", help="Optional case ID filter when the JUnit file contains TC-### identifiers.")
    parser.add_argument("--to", dest="to_case", help="Optional case ID upper bound when the JUnit file contains TC-### identifiers.")
    return parser.parse_args()


def normalize_tc(value: str | None) -> str | None:
    if value is None:
        return None
    cleaned = value.strip().upper()
    return cleaned if re.fullmatch(r"TC-\d+", cleaned) else None


def parse_case_number(value: str) -> int:
    match = re.search(r"(\d+)", value)
    if not match:
        raise ValueError(f"Case ID '{value}' is not in TC-XXX format.")
    return int(match.group(1))


def load_workflow_map(path: Path) -> dict[str, str]:
    data = json.loads(path.read_text(encoding="utf-8"))
    reverse: dict[str, str] = {}
    for tc_id, value in data.items():
        tc_id_norm = tc_id.strip().upper()
        for token in str(value).split("|"):
            token = token.strip()
            if not token:
                continue
            reverse[token] = tc_id_norm
            if "::" in token:
                reverse[token.split("::", 1)[1]] = tc_id_norm
    return reverse


def extract_case_id(testcase: ET.Element, workflow_lookup: dict[str, str]) -> str:
    class_name = testcase.attrib.get("class", "")
    method = testcase.attrib.get("name", "")
    candidates = []
    if class_name and method:
        candidates.append(f"{class_name}::{method}")
    if method:
        candidates.append(method)
    if class_name:
        candidates.append(class_name)

    for candidate in candidates:
        tc_id = workflow_lookup.get(candidate)
        if tc_id:
            return tc_id

    match = re.search(r"tc_(\d{3,})", method, flags=re.IGNORECASE)
    if match:
        return f"TC-{int(match.group(1)):03d}"

    return "TC-UNMAPPED"


def strip_failure_text(node: ET.Element | None) -> str:
    if node is None:
        return "No failure detail captured."
    text = node.text or ""
    message = node.attrib.get("message", "").strip()
    parts = []
    if message:
        parts.append(message)
    cleaned = " ".join(line.strip() for line in text.splitlines() if line.strip())
    if cleaned:
        parts.append(cleaned)
    if not parts:
        return "No failure detail captured."
    joined = " | ".join(parts)
    return joined[:500]


def load_cases(junit_path: Path, workflow_lookup: dict[str, str]):
    root = ET.parse(junit_path).getroot()
    cases = []
    for testcase in root.iter("testcase"):
        failure_node = testcase.find("failure") or testcase.find("error")
        skipped = testcase.find("skipped") is not None
        if failure_node is not None:
            status = "FAIL"
        elif skipped:
            status = "SKIP"
        else:
            status = "PASS"

        class_name = testcase.attrib.get("class", "UnknownClass")
        method = testcase.attrib.get("name", "unknown_test")
        case_id = extract_case_id(testcase, workflow_lookup)
        failure = strip_failure_text(failure_node)
        cases.append({
            "case_id": case_id,
            "class_name": class_name,
            "method": method,
            "status": status,
            "failure": failure,
            "time": float(testcase.attrib.get("time", 0) or 0),
        })
    return cases


def filter_cases(cases: list[dict], start: str | None, end: str | None):
    if start is None and end is None:
        return cases

    start_num = parse_case_number(start) if start else 1
    end_num = parse_case_number(end) if end else 99999
    filtered = []
    for case in cases:
        case_id = case["case_id"]
        if case_id == "TC-UNMAPPED":
            continue
        if not re.fullmatch(r"TC-\d+", case_id):
            continue
        case_num = parse_case_number(case_id)
        if case_num < start_num:
            continue
        if case_num > end_num:
            continue
        filtered.append(case)
    return filtered


def render_report(cases: list[dict], junit_path: Path, from_case: str | None, to_case: str | None) -> str:
    total = len(cases)
    passed = sum(1 for case in cases if case["status"] == "PASS")
    failed = sum(1 for case in cases if case["status"] == "FAIL")
    skipped = sum(1 for case in cases if case["status"] == "SKIP")
    generated_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")

    lines = [
        "WHITE-BOX ALPHA TESTING - TERMINAL EVIDENCE REPORT",
        "=" * 120,
        f"Source: {junit_path.as_posix()}",
        f"Generated: {generated_at}",
        f"Case range: {from_case or 'ALL'} to {to_case or 'LAST'}",
        f"Total cases: {total} | PASS: {passed} | FAIL: {failed} | SKIP: {skipped}",
        "=" * 120,
        "",
    ]

    if not cases:
        lines.append("No test cases matched the requested filter. If the JUnit file is a historical alpha log, use the full report output with all failures included.")
        return "\n".join(lines) + "\n"

    for case in sorted(cases, key=lambda item: parse_case_number(item["case_id"]) if re.fullmatch(r"TC-\d+", item["case_id"]) else 999_999):
        badge = f"[{case['status']}]"
        case_label = case['case_id'] if case['case_id'] != 'TC-UNMAPPED' else case['method']
        lines.append(f"{badge} {case_label} | {case['method']}")
        lines.append(f"  Class: {case['class_name']}")
        if case["status"] == "FAIL":
            lines.append(f"  Evidence: {case['failure']}")
        else:
            lines.append("  Evidence: execution completed without failure/error assertion.")
        lines.append("")

    lines.append("=" * 120)
    lines.append("SUMMARY")
    lines.append(f"- PASS: {passed}")
    lines.append(f"- FAIL: {failed}")
    lines.append(f"- SKIP: {skipped}")
    lines.append(f"- TOTAL: {total}")
    return "\n".join(lines) + "\n"


def main() -> int:
    args = parse_args()
    junit_path = Path(args.junit).resolve()
    workflow_map_path = Path(args.map).resolve()
    output_path = Path(args.output).resolve()

    if not junit_path.exists():
        raise FileNotFoundError(f"JUnit file not found: {junit_path}")
    if not workflow_map_path.exists():
        raise FileNotFoundError(f"Workflow map not found: {workflow_map_path}")

    workflow_lookup = load_workflow_map(workflow_map_path)
    cases = load_cases(junit_path, workflow_lookup)
    from_case = normalize_tc(args.from_case)
    to_case = normalize_tc(args.to_case)
    filtered = filter_cases(cases, from_case, to_case)
    if not args.all:
        filtered = [case for case in filtered if case["status"] in {"FAIL", "SKIP"}]
    if not filtered and (from_case or to_case):
        filtered = [case for case in cases if case["status"] in {"FAIL", "SKIP"}]

    report = render_report(filtered, junit_path, from_case, to_case)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(report, encoding="utf-8")

    print(report, end="")
    print(f"Saved evidence: {output_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

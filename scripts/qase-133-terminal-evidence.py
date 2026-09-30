from collections import defaultdict
from pathlib import Path
import json
import sys
import xml.etree.ElementTree as ET

import openpyxl


def main() -> int:
    if len(sys.argv) not in (4, 5):
        print("Usage: qase-133-terminal-evidence.py <qase.xlsx> <traceability.xlsx> <phpunit-junit.xml> [playwright-junit.xml]")
        return 2

    qase_path, trace_path, junit_path = map(Path, sys.argv[1:4])
    junit_paths = [junit_path] + ([Path(sys.argv[4])] if len(sys.argv) == 5 else [])
    for path in (qase_path, trace_path, *junit_paths):
        if not path.exists():
            print(f"ERROR: Required evidence file not found: {path}")
            return 2

    test_statuses = defaultdict(list)
    for result_path in junit_paths:
        root = ET.parse(result_path).getroot()
        for case in root.iter("testcase"):
            class_name = case.attrib.get("class", "").split("\\")[-1]
            method_name = case.attrib.get("name", "")
            failed = case.find("failure") is not None or case.find("error") is not None
            skipped = case.find("skipped") is not None
            status = "FAIL" if failed or skipped else "PASS"
            test_statuses[class_name].append(status)
            test_statuses[method_name].append(status)
            test_statuses[f"{class_name}::{method_name}"].append(status)

    workflow_map_path = Path(__file__).resolve().parents[1] / "tests" / "qase-workflow-map.json"
    workflow_map = json.loads(workflow_map_path.read_text(encoding="utf-8")) if workflow_map_path.exists() else {}

    trace_book = openpyxl.load_workbook(trace_path, data_only=True, read_only=True)
    trace_sheet = trace_book["Qase Traceability"]
    trace_headers = [cell.value for cell in next(trace_sheet.iter_rows(min_row=1, max_row=1))]
    trace_index = {name: index for index, name in enumerate(trace_headers)}
    trace_rows = {
        row[trace_index["Qase ID"]]: row
        for row in trace_sheet.iter_rows(min_row=2, values_only=True)
    }

    qase_book = openpyxl.load_workbook(qase_path, data_only=True, read_only=True)
    qase_sheet = qase_book.active
    qase_headers = [cell.value for cell in next(qase_sheet.iter_rows(min_row=1, max_row=1))]
    qase_index = {name: index for index, name in enumerate(qase_headers)}
    qase_rows = list(qase_sheet.iter_rows(min_row=2, values_only=True))

    passed = 0
    failed = 0
    print("=" * 112)
    print("PHARMACY WEB BASED - 133 QASE TEST CASE TERMINAL EVIDENCE")
    print("Rule: PASS requires a complete mapped executable backend test and a passing JUnit result.")
    print("=" * 112)

    for source_row in qase_rows:
        case_id = str(source_row[qase_index["ID"]] or "").strip()
        title = str(source_row[qase_index["Title"]] or "Untitled test")
        mapped = trace_rows.get(case_id)

        result = "FAIL"
        reason = "No backend traceability mapping exists."
        evidence = "none"
        if mapped:
            coverage = str(mapped[trace_index["Automation Coverage"]] or "")
            evidence = str(mapped[trace_index["PHPUnit Filter"]] or "none")
            if case_id in workflow_map:
                coverage = "Automated"
                evidence = workflow_map[case_id]
            classes = [name.strip() for name in evidence.split("|") if name.strip()]
            statuses = [status for name in classes for status in test_statuses.get(name, [])]
            if coverage == "Automated" and statuses and "FAIL" not in statuses:
                result = "PASS"
                reason = "Complete mapped backend automation executed successfully."
            elif coverage == "Automated" and "FAIL" in statuses:
                reason = "Mapped backend automation executed with one or more failed assertions/errors."
            elif coverage == "Automated":
                reason = "Mapped backend test was not found in the fresh JUnit execution."
            elif "Manual" in coverage or "Frontend" in coverage:
                reason = "No complete executable backend test; browser/manual scenario is not proven by PHPUnit."
            else:
                reason = "Only supporting/partial backend coverage exists; the full test case was not executed."

        if result == "PASS":
            passed += 1
        else:
            failed += 1
        print(f"[{result}] {case_id} | {title}")
        print(f"       Evidence: {evidence}")
        print(f"       Actual:   {reason}")

    print("=" * 112)
    print(f"FINAL QASE RESULT: {passed} PASSED | {failed} FAILED | {len(qase_rows)} TOTAL")
    print("FAILED includes absent full automation; it must not be interpreted only as an application defect.")
    print("=" * 112)
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())

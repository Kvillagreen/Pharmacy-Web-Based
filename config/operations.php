<?php
return [
    // Enable where staffing permits independent approval of every void.
    'require_distinct_void_approver' => (bool) env('VOID_REQUIRE_DISTINCT_APPROVER', false),
];

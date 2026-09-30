<?php
$branches = App\Models\v1\Branch::get(['branch_id', 'branch_name']);
echo json_encode($branches);

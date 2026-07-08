<?php

header("Content-Type: application/json; charset=UTF-8");

echo json_encode([
    "status" => true,
    "message" => "Orders API is working"
], JSON_UNESCAPED_UNICODE);
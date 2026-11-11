<?php
require_once __DIR__ . '/functions.php';
function generateCsrfToken(): string { return csrf_token(); }
function csrfField(): string { return csrf_field(); }
function validateCsrfToken(?string $token): bool { return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '',$token); }

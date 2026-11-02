<?php
// ============================================================
// env.example.php — Template for environment credentials
// ============================================================
// 1. Copy this file and rename the copy to:  env.php
// 2. Fill in your real values in env.php
// 3. NEVER commit env.php to GitHub (it is in .gitignore)
// ============================================================

// --- Database ------------------------------------------------
define('DB_HOST',    'localhost');          // usually 'localhost'
define('DB_PORT',    '3306');              // 3306 for online hosts; 8111 for XAMPP default install
define('DB_NAME',    'your_database_name');
define('DB_USER',    'your_db_username');
define('DB_PASS',    'your_db_password');

// --- Groq AI -------------------------------------------------
// Get a free key at https://console.groq.com (no credit card needed)
define('AI_API_KEY', 'gsk_YOUR_GROQ_API_KEY_HERE');
define('AI_MODEL',   'openai/gpt-oss-120b');
define('AI_API_URL', 'https://api.groq.com/openai/v1/chat/completions');

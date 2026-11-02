# Academic Assistant AI

An AI-powered personalized learning platform for university students.

## Features

| Feature | Description |
|---|---|
| **Dashboard** | Live stats: documents, quizzes completed, average score, recent activity |
| **My Materials** | Upload PDFs via drag-and-drop; text extracted in-browser via PDF.js |
| **AI Summarizer** | Quick / Detailed / Topic-Based / Key Points summaries from your documents |
| **AI Tutor** | Document-grounded conversational Q&A (never invents answers outside the doc) |
| **Quiz Generator** | 5 / 10 / 15 MCQs · Easy / Medium / Hard · auto-scoring + explanations |
| **Learning Insights** | Score history chart, topic accuracy chart, weak/strong topic breakdown |
| **Recommendations** | AI-generated 7-day study plan based on real quiz performance |
| **Auth** | Register / Login with bcrypt-hashed passwords, PHP session-based auth |

## Tech Stack

- **Frontend**: HTML5, CSS3, Vanilla JavaScript (no frameworks)
- **PDF Processing**: PDF.js 3.11 (client-side text extraction)
- **Charts**: Chart.js 4.4
- **Backend**: PHP 8.1+
- **Database**: MySQL 8.0+
- **AI**: Google Gemini 1.5 Flash (via PHP proxy — API key never exposed to browser)

## Project Structure

```
Academic Assistant AI/
├── index.html          ← Single-page application shell
├── style.css           ← All styles (responsive, Poppins font)
├── script.js           ← All frontend logic
├── database.sql        ← MySQL schema (run once)
├── uploads/            ← PDF files (created automatically)
└── api/
    ├── config.php      ← DB credentials + shared helpers
    ├── auth.php        ← register / login / logout / me
    ├── materials.php   ← upload / list / delete / save_text / get_text
    ├── ai.php          ← summarize / tutor / quiz / recommend (Gemini proxy)
    └── quizzes.php     ← submit / list / detail / stats
```

## Setup Instructions

### 1. Database

Run the SQL script in your MySQL server:

```sql
-- In MySQL Workbench, phpMyAdmin, or CLI:
source /path/to/database.sql
```

Or via CLI:
```bash
mysql -u root -p < database.sql
```

### 2. Configure Credentials

Edit `api/config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'academic_assistant');
define('DB_USER', 'root');       // your MySQL user
define('DB_PASS', '');           // your MySQL password
define('AI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE');
```

> **Get a Gemini API key** (free): https://aistudio.google.com/app/apikey  
> **Never commit your real API key to version control.**

### 3. Web Server

The project requires PHP + MySQL. Use one of:

- **XAMPP** (Windows/Mac/Linux) — copy project to `htdocs/`
- **WAMP** (Windows) — copy to `www/`
- **MAMP** (Mac) — copy to `htdocs/`
- **Laravel Valet** or any local PHP server

Make sure the `uploads/` directory is writable:
```bash
chmod 755 uploads/    # Linux/Mac
```

### 4. Access the App

Open `http://localhost/Academic Assistant AI/index.html` in your browser.

## AI Integration Notes

All AI calls go through `api/ai.php` which acts as a secure server-side proxy:

1. The browser calls `/api/ai.php?action=summarize` (etc.)
2. PHP retrieves the document text from MySQL
3. PHP constructs the full prompt with the document context
4. PHP calls the Gemini API with the secret key (never sent to browser)
5. PHP returns only the AI-generated text to the browser

This pattern keeps the API key safe and allows server-side document retrieval.

## LocalStorage Usage

The app uses PHP sessions + MySQL for all persistent data. LocalStorage is **not** used for sensitive data — the PHP session cookie manages authentication.

## Security

- Passwords hashed with `password_hash(PASSWORD_BCRYPT)`
- All DB queries use PDO prepared statements (SQL injection protection)
- File uploads validated by MIME type (finfo) and size limit
- AI API key stored server-side only
- Session-based auth; each user sees only their own data

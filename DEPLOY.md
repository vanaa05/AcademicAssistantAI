# Deploying Academic Assistant AI — Step-by-Step Guide

This guide is written for beginners. Follow each step in order.  
At the end your app will be live at a public URL with all features working.

---

## What You Need Before Starting

- A **free hosting account** (see options below)  
- A **GitHub account** (to push your code)  
- Your **Groq API key** (starts with `gsk_`) — get one free at <https://console.groq.com>  
- This project folder (`AcademicAssistantAI`) on your computer

---

## Step 1 — Choose a Free PHP + MySQL Host

Pick **one** of these (all are free):

| Host | PHP | MySQL | Free subdomain |
|---|---|---|---|
| [InfinityFree](https://infinityfree.net) | ✅ PHP 8+ | ✅ MySQL | `yourname.infinityfreeapp.com` |
| [Hostinger Free](https://www.hostinger.com/free-web-hosting) | ✅ PHP 8+ | ✅ MySQL | `yourname.freehosting.com` |
| [000WebHost](https://www.000webhost.com) | ✅ PHP 7.4+ | ✅ MySQL | `yourname.000webhostapp.com` |

> **Recommended:** InfinityFree — no ads, free SSL, easy phpMyAdmin.

Sign up and create a hosting account. Note down the **control panel URL** they give you.

---

## Step 2 — Create the MySQL Database

1. Log in to your hosting control panel (cPanel or similar).  
2. Find **MySQL Databases** (or "Database Wizard").  
3. Create a new database — write down the name (e.g. `u123456_academic`).  
4. Create a database user with a strong password — write it down.  
5. Add that user to the database and grant **All Privileges**.  
6. Open **phpMyAdmin** from the control panel.  
7. Click on your database in the left panel.  
8. Click the **SQL** tab at the top.  
9. Open `database.sql` from your project folder in any text editor, copy all the text.  
10. Paste it into the phpMyAdmin SQL box and click **Go**.  
    ✅ You should see "9 queries executed successfully."

---

## Step 3 — Create `env.php` with Your Credentials

1. In your project folder, find `env.example.php`.  
2. Make a **copy** of it in the same folder.  
3. Rename the copy to **`env.php`** (not `env.example.php`).  
4. Open `env.php` in any text editor (Notepad, VS Code, etc.).  
5. Fill in your hosting values:

```php
define('DB_HOST',    'localhost');          // usually 'localhost' — check your host's docs
define('DB_PORT',    '8111');              // 3306 is standard for most hosts
define('DB_NAME',    'academic_assistant');  // the database name you created in Step 2
define('DB_USER',    'root');    // the database username from Step 2
define('DB_PASS',    ' '); // the database password from Step 2

define('AI_API_KEY', 'gsk_abc123...');     // your Groq key from https://console.groq.com
define('AI_MODEL',   'openai/gpt-oss-120b');
define('AI_API_URL', 'https://api.groq.com/openai/v1/chat/completions');
```

6. Save `env.php`.  

> ⚠️ **IMPORTANT:** `env.php` is in `.gitignore` — it will NOT be uploaded to GitHub.  
> Your API key and database password stay secret.

---

## Step 4 — Push the Project to GitHub

> Skip this step if you've already pushed to GitHub and just want to update.

1. Open a terminal (Command Prompt or Git Bash) in your project folder.  
2. Run these commands one by one:

```bash
git init
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/AcademicAssistantAI.git
git push -u origin main
```

3. Go to `github.com/YOUR_USERNAME/AcademicAssistantAI` — confirm `env.php` is **NOT** listed in the file list. If it is missing, that's correct ✅.

---

## Step 5 — Upload Files to Your Web Host

You have two options:

### Option A — File Manager (easiest for beginners)

1. Log in to your hosting control panel.  
2. Open **File Manager**.  
3. Navigate to the `public_html` (or `htdocs`) folder.  
4. Upload **all files** from your project folder **except** the `env.php` file you just created.  
   Upload everything else: `index.html`, `script.js`, `style.css`, the entire `api/` folder, `.htaccess`, etc.  
5. After uploading, use File Manager to create a **new file** called `env.php` directly on the server.  
6. Open it and paste the exact content of your local `env.php` (with the production credentials).  
7. Save it.

### Option B — FTP Client (FileZilla)

1. Download and install [FileZilla](https://filezilla-project.org) (free).  
2. Your host's control panel has an FTP section — find the **FTP hostname**, **username**, and **password**.  
3. Connect to the server with FileZilla.  
4. Navigate to `public_html` on the server (right panel).  
5. Drag all project files from your computer (left panel) to the server (right panel) — **include** `env.php` this time (it's safe because FTP is private).

---

## Step 6 — Set Upload Directory Permissions

The `api/uploads/` folder needs write permission so PHP can save uploaded PDFs.

1. In File Manager or FileZilla, right-click on `api/uploads/` → **Change Permissions** (or **CHMOD**).  
2. Set it to **755** (owner: read+write+execute; group & others: read+execute).  
3. Save.

---

## Step 7 — Run the Setup Check

1. Open your browser and go to:  
   `https://yourdomain.infinityfreeapp.com/setup_check.php`  
   (replace with your actual domain)

2. You should see **all green checkmarks** ✅.

3. If any check fails, read the yellow fix box on that page — it tells you exactly what to do.

**Common issues and fixes:**

| Problem | Fix |
|---|---|
| Database connection failed | Check DB credentials in `env.php` on the server |
| env.php not found | Create `env.php` on the server (Step 3 + Step 5) |
| Upload directory not writable | CHMOD `api/uploads/` to 755 (Step 6) |
| Groq API test fails (cURL error) | Your host may block outgoing HTTPS — try a different host or contact support |
| HTTP 401 from Groq | Your API key is wrong — check `env.php` on the server |

---

## Step 8 — Delete `setup_check.php` from the Server

Once all checks pass:

1. In File Manager, find `setup_check.php` on the server.  
2. **Delete it** (right-click → Delete).  

This keeps your server configuration private.

---

## Step 9 — Test All Features

Visit your site URL and test each feature:

- [ ] Sign Up — create a new account  
- [ ] Login — log in with that account  
- [ ] Dashboard — stats show correctly  
- [ ] My Materials — upload a PDF (under 20 MB)  
- [ ] AI Summarizer — generate a summary of the uploaded PDF  
- [ ] AI Tutor — ask a question about the PDF  
- [ ] Quiz Generator — generate and complete a quiz  
- [ ] Learning Insights — check scores and charts appear  

If anything fails, check the browser console (press **F12** → Console tab) for error messages.

---

## Keeping XAMPP (localhost) Working

Nothing in your XAMPP setup was removed. The app still works locally because:

- `env.php` on your **local machine** has `DB_PORT=8111` (your XAMPP port)  
- `env.php` on the **server** has `DB_PORT=3306` (standard hosting port)  

Two separate `env.php` files — one local, one on the server. They are both gitignored.

To run locally: start XAMPP Apache + MySQL, open `http://localhost/AcademicAssistantAI/`.

---

## Updating the App Later

When you make changes to the code:

1. Push the updated code to GitHub (`git add . && git commit -m "Update" && git push`).  
2. Download the changed files from GitHub (or use the File Manager to update individual files on the server).  
3. **Never overwrite `env.php` on the server** — it contains your production credentials.

---

## Security Checklist

- [x] `env.php` is in `.gitignore` — never committed to GitHub  
- [x] `setup_check.php` deleted from server after setup  
- [x] Groq API key only exists in `env.php` — never in JavaScript or HTML  
- [x] `api/config.php` is protected by `api/.htaccess` — not directly accessible  
- [x] `api/uploads/` blocks PHP execution — uploaded PDFs can't be run as scripts  
- [x] Session cookies are `HttpOnly` and `Secure` on HTTPS  
- [x] `database.sql` is blocked from direct browser access by `.htaccess`  

---

## Split Deployment: GitHub Pages (frontend) + PHP Host (backend)

This section covers the setup where your HTML/CSS/JS lives on **GitHub Pages** and your PHP backend lives on a **separate PHP host** (e.g. InfinityFree, Hostinger).

> ⚠️ **Why this is needed:** GitHub Pages only serves static files. PHP cannot run there. Cloudflare Workers also run JavaScript, not PHP. To keep the full PHP + MySQL backend, you need a real PHP host for the `api/` folder.

### What causes "Network error. Please try again."

When `index.html` is served from `https://username.github.io/repo/`, the JavaScript tries to call `api/auth.php` as a relative URL — which resolves to `https://username.github.io/repo/api/auth.php`. That URL does not exist on GitHub Pages, so the request fails entirely and the `catch` block shows the error message.

### How to fix it — one line in `script.js`

Open `script.js` and find this line near the top (line 19):

```js
const API_BASE = '';   // ← set to your PHP host URL for GitHub Pages deployments
```

Change it to your PHP host's URL (no trailing slash):

```js
const API_BASE = 'https://mysite.infinityfreeapp.com';
```

That's the **only** change needed in `script.js`. All four API endpoints (`auth`, `materials`, `ai`, `quizzes`) will now point to your PHP host automatically.

### Step-by-step for GitHub Pages + PHP host

1. **Deploy the PHP backend** to a PHP + MySQL host (follow Steps 1–8 from above).  
   Your backend URL will be something like `https://mysite.infinityfreeapp.com`.

2. **Edit `script.js`** on your local machine:  
   Find `const API_BASE = '';` and set it to your PHP host URL:
   ```js
   const API_BASE = 'https://mysite.infinityfreeapp.com';
   ```

3. **Push to GitHub** (`git add . && git commit -m "Set API_BASE" && git push`).

4. **Enable GitHub Pages** in your repository:  
   - Go to your repo on GitHub → Settings → Pages  
   - Source: Deploy from a branch → select `main` → folder `/` (root)  
   - Click Save  
   - GitHub will give you a URL like `https://username.github.io/AcademicAssistantAI/`

5. **Test it**: open the GitHub Pages URL, try to Sign Up — it should work now.

### Why sessions work cross-origin

`api/config.php` automatically detects when a request comes from a different domain (by comparing the `Origin` header to the server's host). When cross-origin is detected:
- The session cookie is set with `SameSite=None; Secure` so browsers send it on cross-site requests
- CORS headers already echo back the exact requesting origin with `Access-Control-Allow-Credentials: true`

On XAMPP (localhost), the cookie remains `SameSite=Lax` — nothing changes for local development.

### Important: `script.js` is committed to GitHub

Because `API_BASE` is in `script.js` (a committed file), your PHP host URL will be visible in the public GitHub repository. **This is fine** — the PHP host URL is not a secret. Your actual secrets (DB password, Groq key) remain in `env.php` on the server, which is gitignored and never pushed.


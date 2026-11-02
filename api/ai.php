<?php
// ============================================================
// ai.php — AI proxy for all intelligent academic features
// Keeps the API key server-side; never exposed to the browser.
// ============================================================
require_once __DIR__ . '/config.php';
start_session();

$action = $_GET['action'] ?? '';

match ($action) {
    'summarize'       => handle_summarize(),
    'tutor'           => handle_tutor(),
    'quiz'            => handle_quiz(),
    'recommend'       => handle_recommend(),
    'test_connection' => handle_test_connection(),
    default           => json_error('Unknown AI action', 404),
};

// ============================================================
// groq_request()
// Core HTTP transport — sends a messages array to the Groq API.
// Returns the raw decoded response array or calls json_error().
// ============================================================
function groq_request(array $messages, float $temperature = 0.7, int $max_tokens = 4096): array {
    $api_key = trim(AI_API_KEY);
    if (empty($api_key)) {
        error_log('[AcademicAI] AI_API_KEY is not set. Open api/config.php and paste your Groq key.');
        json_error(
            'The AI service is not configured. ' .
            'Open api/config.php and paste your Groq API key (starts with "gsk_"). ' .
            'Get a free key at https://console.groq.com.',
            503
        );
    }

    $payload = json_encode([
        'model'       => AI_MODEL,
        'messages'    => $messages,
        'temperature' => $temperature,
        'max_tokens'  => $max_tokens,
        'top_p'       => 0.95,
    ]);

    $ca_bundle = find_ca_bundle();
    $ch = curl_init(AI_API_URL);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($ca_bundle) $opts[CURLOPT_CAINFO] = $ca_bundle;
    curl_setopt_array($ch, $opts);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curl_err) {
        error_log('[AcademicAI] cURL error: ' . $curl_err);
        json_error('Could not connect to the AI service. Check your internet connection and try again.', 502);
    }

    if ($http_code !== 200) {
        $body    = json_decode($response, true);
        $api_msg = $body['error']['message'] ?? 'No details returned by the API.';
        error_log('[AcademicAI] Groq API HTTP ' . $http_code . ': ' . $api_msg);
        json_error(ai_error_message($http_code, $api_msg), 502);
    }

    return json_decode($response, true);
}

// ============================================================
// call_ai()
// Simple single-turn call: system prompt + one user message.
// Used by test_connection and as a convenience wrapper.
// ============================================================
function call_ai(string $prompt, string $system = ''): string {
    $messages = [];
    if ($system !== '') {
        $messages[] = ['role' => 'system', 'content' => $system];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $data   = groq_request($messages, 0.7, 4096);
    $reason = $data['choices'][0]['finish_reason'] ?? '';
    if ($reason === 'content_filter') {
        json_error('The AI blocked this response due to content safety filters. Try rephrasing.', 502);
    }
    $text = $data['choices'][0]['message']['content'] ?? '';
    if (empty(trim($text))) {
        error_log('[AcademicAI] Groq returned an empty response.');
        json_error('The AI returned an empty response. Please try again.', 502);
    }
    return $text;
}

// ============================================================
// call_ai_chat()
// Multi-turn call: sends a complete messages array (system +
// alternating user/assistant turns).  Used by the tutor.
// ============================================================
function call_ai_chat(array $messages, float $temperature = 0.7, int $max_tokens = 4096): string {
    $data   = groq_request($messages, $temperature, $max_tokens);
    $reason = $data['choices'][0]['finish_reason'] ?? '';
    if ($reason === 'content_filter') {
        json_error('The AI blocked this response due to content safety filters. Try rephrasing.', 502);
    }
    $text = $data['choices'][0]['message']['content'] ?? '';
    if (empty(trim($text))) {
        error_log('[AcademicAI] Groq returned an empty response.');
        json_error('The AI returned an empty response. Please try again.', 502);
    }
    return $text;
}

// ============================================================
// ai_error_message()
// ============================================================
function ai_error_message(int $http_code, string $api_msg = ''): string {
    return match (true) {
        $http_code === 400 =>
            'The AI request was rejected (HTTP 400). ' .
            'The model name may be invalid. Current model: "' . AI_MODEL . '". ' .
            'Check available models at https://console.groq.com/docs/models. ' .
            'Details: ' . ($api_msg ?: 'Bad Request'),

        $http_code === 401 =>
            'Authentication failed (HTTP 401). Your Groq API key is invalid or revoked. ' .
            'Generate a new key at https://console.groq.com and update api/config.php.',

        $http_code === 403 =>
            'Access denied (HTTP 403). Your Groq API key lacks permission for this request. ' .
            'Verify your key at https://console.groq.com/keys. Details: ' . ($api_msg ?: 'Forbidden'),

        $http_code === 404 =>
            'The AI model "' . AI_MODEL . '" was not found (HTTP 404). ' .
            'Check https://console.groq.com/docs/models and update AI_MODEL in api/config.php. ' .
            'Details: ' . ($api_msg ?: 'Not Found'),

        $http_code === 429 =>
            'AI rate limit exceeded (HTTP 429). The Groq free tier has per-minute and per-day limits. ' .
            'Please wait 30 seconds and try again.',

        $http_code >= 500 =>
            'The Groq AI service is temporarily unavailable (HTTP ' . $http_code . '). ' .
            'Please try again in a few minutes.',

        default =>
            'The AI service returned an unexpected error (HTTP ' . $http_code . '). ' .
            'Details: ' . ($api_msg ?: 'Unknown error'),
    };
}

// ============================================================
// find_ca_bundle()
// ============================================================
function find_ca_bundle(): ?string {
    $candidates = [
        'D:/xampp/apache/bin/curl-ca-bundle.crt',
        'D:/xampp/php/extras/ssl/cacert.pem',
        'C:/xampp/apache/bin/curl-ca-bundle.crt',
        'C:/xampp/php/extras/ssl/cacert.pem',
        __DIR__ . '/../cacert.pem',
        __DIR__ . '/cacert.pem',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/usr/local/etc/openssl/cert.pem',
    ];
    foreach ($candidates as $path) {
        if (file_exists($path) && is_readable($path)) return $path;
    }
    $php_ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
    if ($php_ca && file_exists($php_ca)) return $php_ca;
    return null;
}

// ============================================================
// get_material_text()
// Returns the full extracted text of a material owned by the user.
// ============================================================
function get_material_text(int $material_id, int $user_id): string {
    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT extracted_text, original_name, status FROM materials WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$material_id, $user_id]);
    $row = $stmt->fetch();

    if (!$row) json_error('Material not found or you do not have access to it.', 404);
    if ($row['status'] !== 'ready') json_error('This document is still being processed. Please wait.');
    if (empty($row['extracted_text'])) {
        json_error(
            'No text could be extracted from this document. ' .
            'The PDF may be image-based or password-protected. Try uploading a text-based PDF.'
        );
    }
    return $row['extracted_text'];
}

// ============================================================
// get_material_name()
// Returns original filename for a material owned by the user.
// ============================================================
function get_material_name(int $material_id, int $user_id): string {
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT original_name FROM materials WHERE id = ? AND user_id = ?');
    $stmt->execute([$material_id, $user_id]);
    $row = $stmt->fetch();
    return $row['original_name'] ?? 'Document';
}

// ============================================================
// chunk_document()
// Splits document text into overlapping ~1500-char chunks with
// optional overlap so no sentence is orphaned at a boundary.
// Returns an array of chunk strings.
// ============================================================
function chunk_document(string $text, int $chunk_size = 1500, int $overlap = 200): array {
    $text   = preg_replace('/\s+/', ' ', trim($text));
    $len    = mb_strlen($text);
    if ($len <= $chunk_size) return [$text];

    $chunks = [];
    $start  = 0;
    while ($start < $len) {
        $end = $start + $chunk_size;
        if ($end < $len) {
            // Extend to next sentence boundary to avoid mid-sentence cut
            $boundary = mb_strrpos(mb_substr($text, $start, $chunk_size + 200), '. ');
            if ($boundary !== false && $boundary > $chunk_size / 2) {
                $end = $start + $boundary + 2;
            }
        }
        $chunks[] = mb_substr($text, $start, $end - $start);
        $start    = max($start + 1, $end - $overlap);
    }
    return $chunks;
}

// ============================================================
// find_relevant_chunks()
// Returns the $top_n most relevant chunks for a given query by
// counting keyword overlaps (fast, dependency-free heuristic).
// For summarization use the first N chunks (preserves structure).
// ============================================================
function find_relevant_chunks(array $chunks, string $query, int $top_n = 4): array {
    if (count($chunks) <= $top_n) return $chunks;

    // Tokenise query into meaningful words (≥4 chars, lowercase)
    $query_words = array_filter(
        array_unique(preg_split('/\W+/', mb_strtolower($query))),
        fn($w) => mb_strlen($w) >= 4
    );

    if (empty($query_words)) {
        // No meaningful keywords — return the first chunks (document opening)
        return array_slice($chunks, 0, $top_n);
    }

    // Score each chunk
    $scored = [];
    foreach ($chunks as $i => $chunk) {
        $lower = mb_strtolower($chunk);
        $score = 0;
        foreach ($query_words as $word) {
            $score += substr_count($lower, $word);
        }
        $scored[] = ['idx' => $i, 'score' => $score, 'chunk' => $chunk];
    }

    usort($scored, fn($a, $b) => $b['score'] - $a['score']);
    $top = array_slice($scored, 0, $top_n);

    // Re-sort by original index to preserve document order
    usort($top, fn($a, $b) => $a['idx'] - $b['idx']);

    return array_column($top, 'chunk');
}

// ============================================================
// prepare_doc_context()
// For SUMMARIZATION  : returns the first ~12 000 chars in chunks
//                      (preserves document structure).
// For TUTOR / QUIZ   : returns the top relevant chunks for the
//                      given query, capped at ~8 000 chars.
// ============================================================
function prepare_doc_context(string $doc_text, string $query = '', string $mode = 'full'): string {
    if ($mode === 'full') {
        // For summarization: take as much of the start as the model can handle
        $cap    = 12000;
        $chunks = chunk_document($doc_text, 1800, 100);
        $result = '';
        foreach ($chunks as $chunk) {
            if (mb_strlen($result) + mb_strlen($chunk) > $cap) break;
            $result .= $chunk . "\n\n";
        }
        return trim($result);
    }

    // Relevance mode for tutor / quiz
    $chunks   = chunk_document($doc_text, 1500, 200);
    $relevant = find_relevant_chunks($chunks, $query, 5);
    $cap      = 8000;
    $result   = '';
    foreach ($relevant as $chunk) {
        if (mb_strlen($result) + mb_strlen($chunk) > $cap) break;
        $result .= $chunk . "\n\n";
    }
    return trim($result);
}

// ============================================================
// handle_test_connection()
// ============================================================
function handle_test_connection(): void {
    require_auth();

    $api_key = trim(AI_API_KEY);
    if (empty($api_key)) {
        json_error(
            'API key is not set. Open api/config.php and paste your Groq API key (starts with "gsk_"). ' .
            'Get a free key at https://console.groq.com.',
            503
        );
    }

    try {
        $reply = call_ai('Reply with exactly one word: OK');
        json_ok([
            'status'  => 'connected',
            'model'   => AI_MODEL,
            'message' => 'Groq API is working correctly. All AI features are ready.',
            'sample'  => trim($reply),
        ]);
    } catch (\Throwable $e) {
        json_error('AI connection test failed: ' . $e->getMessage(), 502);
    }
}

// ============================================================
// handle_summarize()
// Intelligent summarization with document-structure awareness.
// ============================================================
function handle_summarize(): void {
    $user = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

    $body        = get_json_body();
    $material_id = (int)($body['material_id'] ?? 0);
    $type        = $body['type'] ?? 'quick';

    if ($material_id <= 0) json_error('material_id is required.');

    $valid_types = ['quick', 'detailed', 'topic', 'keypoints'];
    if (!in_array($type, $valid_types, true)) {
        json_error('Invalid summary type. Must be one of: ' . implode(', ', $valid_types));
    }

    $raw_text  = get_material_text($material_id, $user['id']);
    $doc_name  = get_material_name($material_id, $user['id']);
    $doc_text  = prepare_doc_context($raw_text, '', 'full');

    // ── System prompt: establishes the AI's role and strict rules ──
    $system = <<<SYS
You are an expert academic summarizer and teaching assistant. You produce high-quality, accurate summaries of university-level academic documents.

STRICT RULES:
1. Base your output EXCLUSIVELY on the document text provided. Do NOT add information from outside the document.
2. Preserve the precise meaning of every academic concept, definition, formula, and argument.
3. Detect and respond in the same language as the document (Indonesian or English).
4. Use clear Markdown formatting: headings (##, ###), bullet points, and **bold** for key terms.
5. Never fabricate section numbers, page references, or content not present in the document.
6. If the document covers multiple topics, reflect that structure in the output.
SYS;

    // ── Task-specific user instruction ──
    $task_instructions = [
        'quick' =>
            "Generate a QUICK SUMMARY (150–250 words) of the document \"{$doc_name}\".\n" .
            "- Identify and state the document's main thesis or purpose.\n" .
            "- Summarize the 3–5 most important ideas or findings.\n" .
            "- Conclude with the document's key takeaway.\n" .
            "- Use clear paragraphs. No bullet lists.",

        'detailed' =>
            "Generate a DETAILED SUMMARY of the document \"{$doc_name}\" (target 500–800 words).\n" .
            "- Open with a one-paragraph overview of the document's purpose and scope.\n" .
            "- Use ## headings for each major topic or chapter found in the document.\n" .
            "- Under each heading, explain the key arguments, concepts, methods, or findings in depth.\n" .
            "- Highlight relationships and connections between topics.\n" .
            "- Close with a synthesis paragraph that draws the main ideas together.",

        'topic' =>
            "Generate a TOPIC-BASED SUMMARY of the document \"{$doc_name}\".\n" .
            "- Identify every distinct topic, chapter, or section in the document.\n" .
            "- For each topic, write a focused paragraph under a ## heading.\n" .
            "- Capture the main idea, supporting details, and any definitions or examples given for that topic.\n" .
            "- Order topics as they appear in the document.",

        'keypoints' =>
            "Extract KEY POINTS from the document \"{$doc_name}\".\n" .
            "- Identify all important concepts, definitions, formulas, methods, laws, theories, and terminology.\n" .
            "- Group them under clear ## category headings.\n" .
            "- Present each key point as a concise bullet with **bold** label and plain-language explanation.\n" .
            "- Include any important examples, data, or relationships mentioned in the document.",
    ];

    $user_message = $task_instructions[$type] . "\n\n---\nDOCUMENT TEXT:\n" . $doc_text . "\n---";

    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user_message],
    ];

    $summary = call_ai_chat($messages, 0.5, 4096);

    // Save to DB
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO summaries (user_id, material_id, summary_type, content) VALUES (?, ?, ?, ?)'
    )->execute([$user['id'], $material_id, $type, $summary]);

    json_ok(['summary' => $summary, 'type' => $type]);
}

// ============================================================
// handle_tutor()
// Context-aware multi-turn tutor with smart document retrieval.
// ============================================================
function handle_tutor(): void {
    $user = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

    $body        = get_json_body();
    $material_id = (int)($body['material_id'] ?? 0);
    $question    = trim($body['question'] ?? '');
    $session_id  = (int)($body['session_id'] ?? 0);
    $history     = is_array($body['history'] ?? null) ? $body['history'] : [];

    if ($material_id <= 0) json_error('material_id is required.');
    if (empty($question))  json_error('question is required.');

    $raw_text = get_material_text($material_id, $user['id']);
    $doc_name = get_material_name($material_id, $user['id']);
    $pdo      = db();

    // Create or retrieve session
    if ($session_id <= 0) {
        $pdo->prepare(
            'INSERT INTO tutor_sessions (user_id, material_id) VALUES (?, ?)'
        )->execute([$user['id'], $material_id]);
        $session_id = (int)$pdo->lastInsertId();
    }

    // Build a combined query from recent history + current question
    // so relevance scoring understands multi-turn context
    $context_query = $question;
    foreach (array_slice($history, -4) as $msg) {
        $context_query .= ' ' . ($msg['content'] ?? '');
    }

    // Retrieve the most relevant document sections for this question
    $doc_context = prepare_doc_context($raw_text, $context_query, 'relevant');

    // ── System prompt: persistent tutor persona ──
    $system = <<<SYS
You are an expert, patient, and encouraging academic tutor. You are helping a university student understand the document described below.

DOCUMENT: "{$doc_name}"

YOUR BEHAVIOR:
1. Answer questions using the provided document excerpts as your PRIMARY source. Always prefer document-grounded answers.
2. When the answer is clearly in the document, provide it with a precise explanation. Reference the specific concept, topic, or section by name when relevant.
3. When the answer requires information NOT in the document, clearly state: "This isn't covered in the provided document, but based on general academic knowledge:" — then give a helpful answer labeled as general knowledge.
4. NEVER invent document content, page numbers, section numbers, or facts not present in the excerpts.
5. Adapt to the student's language: if they write in Indonesian, respond fully in natural Indonesian. If English, respond in English.
6. Adapt your explanation depth to the question:
   - Simple factual questions → concise, direct answers.
   - "Explain" or "What is" → step-by-step breakdown with examples and analogies.
   - "Compare" → structured comparison (table or parallel bullet points).
   - "How" or "Why" → causal reasoning with clear logical steps.
   - Follow-up questions → build on previous context without re-explaining already established points.
7. Use Markdown for structure when the response benefits from it (lists, code, tables, bold key terms).
8. Do NOT introduce yourself, greet the student, or add unnecessary preamble — respond directly and helpfully.

RELEVANT DOCUMENT EXCERPTS:
---
{$doc_context}
---
SYS;

    // ── Build the full message chain ──
    // Start with system, then replay conversation history (last 8 turns max),
    // then add the current question
    $messages = [['role' => 'system', 'content' => $system]];

    // Validate and add history (accept role: user|assistant only)
    foreach (array_slice($history, -8) as $msg) {
        $role    = ($msg['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = trim($msg['content'] ?? '');
        if ($content !== '') {
            $messages[] = ['role' => $role, 'content' => $content];
        }
    }

    // Add the current question
    $messages[] = ['role' => 'user', 'content' => $question];

    $answer = call_ai_chat($messages, 0.65, 4096);

    // Save messages to DB
    $pdo->prepare(
        'INSERT INTO tutor_messages (session_id, role, content) VALUES (?, ?, ?)'
    )->execute([$session_id, 'user', $question]);
    $pdo->prepare(
        'INSERT INTO tutor_messages (session_id, role, content) VALUES (?, ?, ?)'
    )->execute([$session_id, 'assistant', $answer]);

    json_ok(['answer' => $answer, 'session_id' => $session_id]);
}

// ============================================================
// handle_quiz()
// Difficulty-calibrated quiz generation with topic diversity.
// ============================================================
function handle_quiz(): void {
    $user = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

    $body        = get_json_body();
    $material_id = (int)($body['material_id'] ?? 0);
    $count       = (int)($body['count'] ?? 10);
    $difficulty  = $body['difficulty'] ?? 'medium';

    if ($material_id <= 0) json_error('material_id is required.');
    $count      = in_array($count, [5, 10, 15]) ? $count : 10;
    $difficulty = in_array($difficulty, ['easy', 'medium', 'hard']) ? $difficulty : 'medium';

    $raw_text = get_material_text($material_id, $user['id']);
    $doc_name = get_material_name($material_id, $user['id']);
    // For quiz generation, use a broad sample of the document
    $doc_text = prepare_doc_context($raw_text, '', 'full');

    // Bloom's taxonomy–aligned difficulty descriptions
    $diff_config = [
        'easy' => [
            'label'    => 'EASY',
            'bloom'    => 'Remember and Understand',
            'guidance' => implode("\n", [
                '- Test recall of key terms, definitions, and basic facts stated in the document.',
                '- Questions should be answerable by reading one or two sentences from the document.',
                '- Distractors (wrong options) should be plausible but clearly distinguishable.',
                '- Avoid trick questions; the correct answer should be unambiguous.',
            ]),
        ],
        'medium' => [
            'label'    => 'MEDIUM',
            'bloom'    => 'Apply and Analyze',
            'guidance' => implode("\n", [
                '- Test understanding and application of concepts from the document.',
                '- Some questions should require combining information from two or more parts of the document.',
                '- Include questions that ask students to interpret, compare, or explain relationships.',
                '- Distractors should reflect common misconceptions or partially correct answers.',
            ]),
        ],
        'hard' => [
            'label'    => 'HARD',
            'bloom'    => 'Evaluate and Synthesize',
            'guidance' => implode("\n", [
                '- Test critical thinking, evaluation, and synthesis of document concepts.',
                '- Include scenario-based questions where students apply theory to a situation.',
                '- Ask students to evaluate the validity of a claim or identify an assumption.',
                '- Distractors should be very close in meaning; require careful reading to distinguish.',
                '- Some questions may involve inferring information not stated literally but clearly implied.',
            ]),
        ],
    ];

    $dc = $diff_config[$difficulty];

    $system = <<<SYS
You are an expert academic assessment designer specializing in university-level examinations.
Your task is to generate high-quality, pedagogically sound multiple-choice questions.

QUALITY STANDARDS:
- Every question must be directly grounded in the provided document content.
- Questions must genuinely test understanding, not just copy sentences from the text.
- Each question must have exactly ONE unambiguously correct answer.
- Wrong options must be plausible (related to the topic) but clearly incorrect upon reflection.
- Explanations must justify WHY the correct answer is right (referencing the document concept).
- Cover a VARIETY of distinct topics from throughout the document — do not cluster on one section.
- Detect the document's language and write all questions in that language.

OUTPUT: Return ONLY a valid JSON array. No markdown fences, no text before or after the JSON.
SYS;

    $user_message = <<<PROMPT
Document: "{$doc_name}"
Difficulty: {$dc['label']} — Bloom's Level: {$dc['bloom']}

Difficulty-specific guidance:
{$dc['guidance']}

Generate exactly {$count} questions. Spread them across different topics/sections of the document.

Required JSON format (array of objects, nothing else):
[
  {
    "question": "Clear, complete question text",
    "options": {"A": "Option text", "B": "Option text", "C": "Option text", "D": "Option text"},
    "correct": "A",
    "explanation": "Explanation of why this answer is correct, referencing the document concept.",
    "topic": "2-5 word topic label from the document"
  }
]

DOCUMENT TEXT:
---
{$doc_text}
---
PROMPT;

    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user_message],
    ];

    $raw = call_ai_chat($messages, 0.6, 6000);

    // ── Clean and extract JSON ──
    $raw = trim($raw);
    $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
    $raw = preg_replace('/\s*```\s*$/', '', $raw);
    $raw = trim($raw);

    // If the model wrapped the array in an object, extract the array
    if (!str_starts_with($raw, '[')) {
        if (preg_match('/\[[\s\S]+\]/m', $raw, $matches)) {
            $raw = $matches[0];
        }
    }

    $questions = json_decode($raw, true);

    if (!is_array($questions) || count($questions) === 0) {
        error_log('[AcademicAI] Quiz invalid JSON. Raw: ' . substr($raw, 0, 500));
        json_error(
            'The AI returned an invalid quiz format. Please try again. ' .
            'If this persists, try a different document or fewer questions.',
            502
        );
    }

    // ── Validate and normalise each question ──
    $valid_letters = ['A', 'B', 'C', 'D'];
    $validated = [];
    $seen_questions = [];

    foreach (array_slice($questions, 0, $count + 5) as $q) {
        if (count($validated) >= $count) break;
        if (empty($q['question']) || !is_array($q['options'] ?? null) || empty($q['correct'])) continue;

        $qtext = trim((string)($q['question'] ?? ''));
        if (empty($qtext) || isset($seen_questions[mb_strtolower($qtext)])) continue;

        // Ensure all four options exist and are non-empty
        $options = [];
        $all_ok  = true;
        foreach ($valid_letters as $letter) {
            $opt = trim((string)($q['options'][$letter] ?? ''));
            if (empty($opt)) { $all_ok = false; break; }
            $options[$letter] = $opt;
        }
        if (!$all_ok) continue;

        $correct = strtoupper(substr(trim((string)($q['correct'] ?? '')), 0, 1));
        if (!in_array($correct, $valid_letters, true)) continue;

        $seen_questions[mb_strtolower($qtext)] = true;
        $validated[] = [
            'question'    => $qtext,
            'options'     => $options,
            'correct'     => $correct,
            'explanation' => trim((string)($q['explanation'] ?? '')),
            'topic'       => trim((string)($q['topic'] ?? 'General')),
        ];
    }

    if (count($validated) === 0) {
        json_error('All quiz questions were malformed. Please try again.', 502);
    }

    $pdo = db();
    $pdo->prepare(
        'INSERT INTO quizzes (user_id, material_id, question_count, difficulty, questions_json)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $user['id'],
        $material_id,
        count($validated),
        $difficulty,
        json_encode($validated),
    ]);
    $quiz_id = (int)$pdo->lastInsertId();

    json_ok(['quiz_id' => $quiz_id, 'questions' => $validated]);
}

// ============================================================
// handle_recommend()
// Data-driven personalized learning recommendations.
// ============================================================
function handle_recommend(): void {
    $user = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

    $body        = get_json_body();
    $material_id = (int)($body['material_id'] ?? 0);

    $pdo = db();

    // ── Fetch real performance data from DB ──
    $stmt = $pdo->prepare(
        'SELECT topic, correct_count, incorrect_count
         FROM topic_performance
         WHERE user_id = ? AND incorrect_count > 0
         ORDER BY incorrect_count DESC
         LIMIT 10'
    );
    $stmt->execute([$user['id']]);
    $weak_topics = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT topic, correct_count, incorrect_count
         FROM topic_performance
         WHERE user_id = ?
         ORDER BY (correct_count + incorrect_count) DESC
         LIMIT 15'
    );
    $stmt->execute([$user['id']]);
    $all_topics = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT q.score, q.difficulty, q.question_count, q.completed_at, m.original_name
         FROM quizzes q
         JOIN materials m ON m.id = q.material_id
         WHERE q.user_id = ? AND q.score IS NOT NULL
         ORDER BY q.completed_at DESC
         LIMIT 8'
    );
    $stmt->execute([$user['id']]);
    $recent_quizzes = $stmt->fetchAll();

    if (empty($recent_quizzes) && empty($all_topics)) {
        json_error(
            'No quiz data found yet. Complete at least one quiz to receive personalized recommendations.',
            400
        );
    }

    // ── Compute derived statistics for the prompt ──
    $total_correct   = array_sum(array_column($all_topics, 'correct_count'));
    $total_incorrect = array_sum(array_column($all_topics, 'incorrect_count'));
    $total_attempts  = $total_correct + $total_incorrect;
    $overall_acc     = $total_attempts > 0
        ? round(($total_correct / $total_attempts) * 100, 1)
        : 0;

    $avg_score = 0;
    if (!empty($recent_quizzes)) {
        $avg_score = round(array_sum(array_column($recent_quizzes, 'score')) / count($recent_quizzes), 1);
    }

    // Optionally include document context for targeted section references
    $doc_context = '';
    $doc_name    = '';
    if ($material_id > 0) {
        try {
            $raw      = get_material_text($material_id, $user['id']);
            $doc_name = get_material_name($material_id, $user['id']);
            // Use a broad chunk of the document for recommendations
            $doc_context = prepare_doc_context($raw, '', 'full');
        } catch (\Throwable $e) {
            $doc_context = '';
        }
    }

    // ── Format data as structured strings ──
    $weak_topics_text = empty($weak_topics)
        ? 'No weak topics identified — the student has answered all attempted topics correctly so far.'
        : implode("\n", array_map(
            fn($t) => sprintf(
                '  - %s: %d incorrect / %d correct (accuracy: %d%%)',
                $t['topic'],
                $t['incorrect_count'],
                $t['correct_count'],
                $t['correct_count'] + $t['incorrect_count'] > 0
                    ? round($t['correct_count'] / ($t['correct_count'] + $t['incorrect_count']) * 100)
                    : 0
            ),
            $weak_topics
        ));

    $all_topics_text = empty($all_topics)
        ? 'No topic data available yet.'
        : implode("\n", array_map(
            fn($t) => sprintf(
                '  - %s: %d correct, %d incorrect (accuracy: %d%%)',
                $t['topic'],
                $t['correct_count'],
                $t['incorrect_count'],
                $t['correct_count'] + $t['incorrect_count'] > 0
                    ? round($t['correct_count'] / ($t['correct_count'] + $t['incorrect_count']) * 100)
                    : 0
            ),
            $all_topics
        ));

    $quiz_history_text = empty($recent_quizzes)
        ? 'No quizzes completed yet.'
        : implode("\n", array_map(
            fn($q) => sprintf(
                '  - %s difficulty on "%s": %.0f%% (%d questions) — %s',
                ucfirst($q['difficulty']),
                $q['original_name'],
                $q['score'],
                $q['question_count'],
                date('d M Y', strtotime($q['completed_at']))
            ),
            $recent_quizzes
        ));

    $doc_section = $doc_context && $doc_name
        ? "REFERENCE DOCUMENT: \"{$doc_name}\"\n---\n{$doc_context}\n---"
        : 'No document selected for this request.';

    // ── System prompt: analytical learning coach ──
    $system = <<<SYS
You are an expert academic learning coach and educational data analyst.
You analyze a student's actual quiz performance data and generate specific, actionable, evidence-based study recommendations.

ANALYSIS PRINCIPLES:
1. Base ALL observations and recommendations exclusively on the provided data. Do not fabricate or assume results not shown.
2. Identify patterns: topics where mistakes cluster, score trends, difficulty progression.
3. Be specific: name the exact topics the student must focus on, explain why, and describe how.
4. Be encouraging but honest — acknowledge both strengths and areas needing improvement.
5. Detect the student's likely language context (Indonesian or English) from the topic names and respond in that language.
6. If document sections are provided, reference specific parts the student should re-read.
7. Recommendations must be realistic, time-bound, and directly tied to the performance data.
SYS;

    $user_message = <<<PROMPT
STUDENT PERFORMANCE SUMMARY:
Overall accuracy across all topics: {$overall_acc}%
Average recent quiz score: {$avg_score}%

WEAK TOPICS (topics with incorrect answers, sorted by most mistakes):
{$weak_topics_text}

ALL TOPICS ATTEMPTED (performance overview):
{$all_topics_text}

RECENT QUIZ HISTORY (most recent first):
{$quiz_history_text}

{$doc_section}

Generate a comprehensive, personalized study recommendation report in Markdown with these exact sections:

## 📊 Performance Overview
Analyze the student's overall academic standing based strictly on the data above.
Identify 2–3 specific patterns or trends (e.g., consistent weakness in a topic cluster, score improvement/decline, difficulty level gaps).

## ⚠️ Priority Topics to Review
For each weak topic identified in the data:
- State the topic name and its accuracy rate.
- Explain what type of mistake is likely occurring (recall gap, conceptual misunderstanding, or application error).
- Give one specific study strategy for that topic.

## 📖 Recommended Study Sections
Based on the weak topics and the reference document (if provided), point to specific sections, concepts, or chapters the student should re-read or focus on.
If no document is provided, recommend study approaches based on the topic names.

## 📅 7-Day Study Plan
A realistic day-by-day plan targeting the weakest areas first.
Each day: one focus topic + one specific activity (e.g., "re-read section on X, then practice 5 questions on Y").

## ✏️ Practice Questions
Generate exactly 3 targeted practice questions for the student's top 3 weakest topics.
After each question, provide the correct answer and a brief explanation.

Keep the tone encouraging and academic. Base everything on the actual data provided — no generic advice.
PROMPT;

    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user_message],
    ];

    $recommendations = call_ai_chat($messages, 0.55, 4096);
    json_ok(['recommendations' => $recommendations]);
}

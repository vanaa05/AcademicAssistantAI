<?php
// ============================================================
// quizzes.php — submit quiz, list quizzes, get quiz detail, stats
// ============================================================
require_once __DIR__ . '/config.php';
start_session();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

match ($action) {
    'submit'   => handle_submit(),
    'list'     => handle_list(),
    'detail'   => handle_detail(),
    'stats'    => handle_stats(),
    default    => json_error('Unknown action', 404),
};

// ─── Submit quiz answers ──────────────────────────────────────
function handle_submit(): void {
    $user = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }
    $body      = get_json_body();
    $quiz_id   = (int)($body['quiz_id'] ?? 0);
    $answers   = $body['answers'] ?? [];   // [{question_index, selected_option}]
    $questions = $body['questions'] ?? []; // full question objects from frontend

    if ($quiz_id <= 0 || empty($questions)) {
        json_error('quiz_id and questions required.');
    }

    $pdo  = db();
    // Verify ownership
    $stmt = $pdo->prepare('SELECT id, score, material_id FROM quizzes WHERE id = ? AND user_id = ?');
    $stmt->execute([$quiz_id, $user['id']]);
    $quiz = $stmt->fetch();
    if (!$quiz) {
        json_error('Quiz not found.', 404);
    }

    // Build answers map
    $answer_map = [];
    foreach ($answers as $a) {
        $answer_map[(int)$a['question_index']] = strtoupper($a['selected_option'] ?? '');
    }

    $correct_count = 0;
    $total         = count($questions);
    $insert_stmt   = $pdo->prepare(
        'INSERT INTO quiz_answers (quiz_id, question_index, question_text, topic, selected_option, correct_option, is_correct)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($questions as $idx => $q) {
        $selected    = $answer_map[$idx] ?? null;
        $correct_opt = strtoupper($q['correct'] ?? '');
        $is_correct  = ($selected !== null && $selected === $correct_opt) ? 1 : 0;
        if ($is_correct) {
            $correct_count++;
        }
        $insert_stmt->execute([
            $quiz_id,
            $idx,
            $q['question'] ?? '',
            $q['topic'] ?? null,
            $selected ?: null,
            $correct_opt,
            $is_correct,
        ]);
    }

    $score = $total > 0 ? (int)round(($correct_count / $total) * 100) : 0;

    // Update quiz record
    $pdo->prepare(
        'UPDATE quizzes SET score = ?, completed_at = NOW() WHERE id = ?'
    )->execute([$score, $quiz_id]);

    // Update learning_progress (upsert)
    $pdo->prepare(
        'INSERT INTO learning_progress (user_id, material_id, total_quizzes, total_questions, total_correct, avg_score)
         VALUES (?, ?, 1, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           total_quizzes   = total_quizzes + 1,
           total_questions = total_questions + VALUES(total_questions),
           total_correct   = total_correct   + VALUES(total_correct),
           avg_score       = (total_correct + VALUES(total_correct)) / (total_questions + VALUES(total_questions)) * 100'
    )->execute([
        $user['id'],
        $quiz['material_id'],
        $total,
        $correct_count,
        $score,
    ]);

    // Update topic_performance
    $tp_stmt = $pdo->prepare(
        'INSERT INTO topic_performance (user_id, topic, correct_count, incorrect_count)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           correct_count   = correct_count   + VALUES(correct_count),
           incorrect_count = incorrect_count + VALUES(incorrect_count)'
    );
    foreach ($questions as $idx => $q) {
        $topic = trim($q['topic'] ?? 'General');
        if (empty($topic)) {
            $topic = 'General';
        }
        $selected   = $answer_map[$idx] ?? null;
        $correct_opt = strtoupper($q['correct'] ?? '');
        $is_correct  = ($selected !== null && $selected === $correct_opt) ? 1 : 0;
        $tp_stmt->execute([
            $user['id'],
            $topic,
            $is_correct,
            $is_correct ? 0 : 1,
        ]);
    }

    json_ok([
        'score'          => $score,
        'correct_count'  => $correct_count,
        'total'          => $total,
        'quiz_id'        => $quiz_id,
    ]);
}

// ─── List quizzes ────────────────────────────────────────────
function handle_list(): void {
    $user = require_auth();
    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT q.id, q.question_count, q.difficulty, q.score, q.completed_at, q.created_at,
                m.original_name AS material_name
         FROM quizzes q
         JOIN materials m ON m.id = q.material_id
         WHERE q.user_id = ?
         ORDER BY q.created_at DESC
         LIMIT 50'
    );
    $stmt->execute([$user['id']]);
    json_ok($stmt->fetchAll());
}

// ─── Detail ──────────────────────────────────────────────────
function handle_detail(): void {
    $user     = require_auth();
    $quiz_id  = (int)($_GET['id'] ?? 0);
    if ($quiz_id <= 0) {
        json_error('Quiz id required.');
    }
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT * FROM quizzes WHERE id = ? AND user_id = ?');
    $stmt->execute([$quiz_id, $user['id']]);
    $quiz = $stmt->fetch();
    if (!$quiz) {
        json_error('Quiz not found.', 404);
    }
    $quiz['questions_json'] = json_decode($quiz['questions_json'], true);

    $stmt = $pdo->prepare('SELECT * FROM quiz_answers WHERE quiz_id = ? ORDER BY question_index');
    $stmt->execute([$quiz_id]);
    $quiz['answers'] = $stmt->fetchAll();

    json_ok($quiz);
}

// ─── Stats ───────────────────────────────────────────────────
function handle_stats(): void {
    $user = require_auth();
    $pdo  = db();

    // Overall stats
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total_quizzes,
                COALESCE(AVG(score), 0) AS avg_score,
                SUM(question_count) AS total_questions
         FROM quizzes
         WHERE user_id = ? AND score IS NOT NULL'
    );
    $stmt->execute([$user['id']]);
    $overall = $stmt->fetch();

    // Score history (last 10 completed quizzes)
    $stmt = $pdo->prepare(
        'SELECT q.id, q.score, q.difficulty, q.completed_at, m.original_name AS material_name
         FROM quizzes q
         JOIN materials m ON m.id = q.material_id
         WHERE q.user_id = ? AND q.score IS NOT NULL
         ORDER BY q.completed_at DESC
         LIMIT 10'
    );
    $stmt->execute([$user['id']]);
    $history = $stmt->fetchAll();

    // Topic performance
    $stmt = $pdo->prepare(
        'SELECT topic, correct_count, incorrect_count,
                ROUND(correct_count / (correct_count + incorrect_count) * 100, 1) AS accuracy
         FROM topic_performance
         WHERE user_id = ?
         ORDER BY (correct_count + incorrect_count) DESC
         LIMIT 20'
    );
    $stmt->execute([$user['id']]);
    $topics = $stmt->fetchAll();

    // Total materials
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM materials WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $materials = $stmt->fetch();

    json_ok([
        'overall'   => $overall,
        'history'   => $history,
        'topics'    => $topics,
        'materials' => (int)$materials['total'],
    ]);
}

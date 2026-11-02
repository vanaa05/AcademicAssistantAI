-- ============================================================
-- Academic Assistant AI — Database Schema
-- Run this script once in your MySQL server to set up the DB
-- ============================================================

CREATE DATABASE IF NOT EXISTS academic_assistant CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE academic_assistant;

-- ------------------------------------------------------------
-- Users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name   VARCHAR(150)        NOT NULL,
    email       VARCHAR(255)        NOT NULL UNIQUE,
    password    VARCHAR(255)        NOT NULL,   -- bcrypt hash
    created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login  DATETIME            NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Uploaded Materials
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS materials (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED        NOT NULL,
    filename        VARCHAR(255)        NOT NULL,
    original_name   VARCHAR(255)        NOT NULL,
    file_size       BIGINT UNSIGNED     NOT NULL,
    page_count      INT UNSIGNED        NULL,
    extracted_text  LONGTEXT            NULL,
    status          ENUM('processing','ready','error') NOT NULL DEFAULT 'processing',
    uploaded_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- AI Summaries
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS summaries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED        NOT NULL,
    material_id     INT UNSIGNED        NOT NULL,
    summary_type    ENUM('quick','detailed','topic','keypoints') NOT NULL,
    content         LONGTEXT            NOT NULL,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES materials(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- AI Tutor Conversations
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tutor_sessions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED        NOT NULL,
    material_id INT UNSIGNED        NOT NULL,
    created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES materials(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tutor_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id  INT UNSIGNED        NOT NULL,
    role        ENUM('user','assistant') NOT NULL,
    content     TEXT                NOT NULL,
    created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES tutor_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Quizzes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quizzes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED        NOT NULL,
    material_id     INT UNSIGNED        NOT NULL,
    question_count  TINYINT UNSIGNED    NOT NULL,
    difficulty      ENUM('easy','medium','hard') NOT NULL,
    questions_json  LONGTEXT            NOT NULL,   -- full generated Q&A JSON
    score           TINYINT UNSIGNED    NULL,        -- percentage 0-100
    completed_at    DATETIME            NULL,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES materials(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quiz_answers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id         INT UNSIGNED        NOT NULL,
    question_index  TINYINT UNSIGNED    NOT NULL,
    question_text   TEXT                NOT NULL,
    topic           VARCHAR(255)        NULL,
    selected_option CHAR(1)             NULL,        -- A/B/C/D or NULL
    correct_option  CHAR(1)             NOT NULL,
    is_correct      TINYINT(1)          NOT NULL DEFAULT 0,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Learning Progress (aggregate stats updated after each quiz)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS learning_progress (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED        NOT NULL,
    material_id     INT UNSIGNED        NOT NULL,
    total_quizzes   SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
    total_questions SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
    total_correct   SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
    avg_score       DECIMAL(5,2)        NOT NULL DEFAULT 0.00,
    last_activity   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_material (user_id, material_id),
    FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES materials(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Topic Performance (per quiz weak/strong topics)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS topic_performance (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED        NOT NULL,
    topic           VARCHAR(255)        NOT NULL,
    correct_count   SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
    incorrect_count SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
    UNIQUE KEY uq_user_topic (user_id, topic),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

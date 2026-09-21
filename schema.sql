-- Singh Fitness Gym - database schema
-- Import this in phpMyAdmin before running anything else.

CREATE DATABASE IF NOT EXISTS singh_fitness
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE singh_fitness;


CREATE TABLE members (
  id              VARCHAR(50) PRIMARY KEY,
  member_code     VARCHAR(30) NOT NULL UNIQUE,   -- the "SFG-1042" style code shown on the pass
  name            VARCHAR(100) NOT NULL,
  email           VARCHAR(150) UNIQUE,
  password_hash   VARCHAR(255),                  -- null for members the admin created manually
  phone           VARCHAR(20),

  -- XAMPP has no mail server, so recovery is a security question instead of an
  -- emailed reset link. The answer is hashed the same way as the password.
  security_question   VARCHAR(150),
  security_answer_hash VARCHAR(255),
  address         VARCHAR(255),

  height          DECIMAL(5,2),                  -- cm
  weight          DECIMAL(5,2),                  -- kg
  target_weight   DECIMAL(5,2),
  goals           TEXT,

  preferred_shift ENUM('Morning Shift','Evening Shift') DEFAULT 'Morning Shift',
  gym_access      VARCHAR(100),                  -- plan label incl. price, e.g. "Full gym plus cardio [ 1700 rupee/month ]"
  membership_duration VARCHAR(30),               -- "1 Month" / "2 Months" / "3 Months"

  status          ENUM('Active','Expiring Soon','Expired','Pending Approval') DEFAULT 'Pending Approval',
  role            ENUM('member','admin','pending','visitor') DEFAULT 'pending',

  joined_date     DATE,
  expiry_date     DATE,

  streak          INT DEFAULT 0,
  points          INT DEFAULT 0,
  referrals       INT DEFAULT 0,
  absent_days     INT DEFAULT 0,

  trainer_name    VARCHAR(100),
  trainer_phone   VARCHAR(20),
  trainer_note    TEXT,

  workout_plan_id VARCHAR(50),
  diet_plan_id    VARCHAR(50),

  water_intake    DECIMAL(4,2) DEFAULT 0,        -- litres, resets daily
  sleep_hours     DECIMAL(4,2) DEFAULT 0,

  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_status (status),
  INDEX idx_role (role),
  INDEX idx_expiry (expiry_date)
) ENGINE=InnoDB;


CREATE TABLE member_weights (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  member_id   VARCHAR(50) NOT NULL,
  logged_on   DATE NOT NULL,
  weight      DECIMAL(5,2) NOT NULL,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  UNIQUE KEY one_entry_per_day (member_id, logged_on)
) ENGINE=InnoDB;


CREATE TABLE member_attendance (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  member_id   VARCHAR(50) NOT NULL,
  attended_on DATE NOT NULL,
  scanned_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  -- stops a member scanning the QR twice in one day to farm points
  UNIQUE KEY one_scan_per_day (member_id, attended_on)
) ENGINE=InnoDB;


-- Tick marks on exercises and meals. These reset daily, so we store the date
-- rather than a plain boolean like the old Firestore version did.
CREATE TABLE exercise_completions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  member_id   VARCHAR(50) NOT NULL,
  exercise_id VARCHAR(50) NOT NULL,
  done_on     DATE NOT NULL,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_done (member_id, exercise_id, done_on)
) ENGINE=InnoDB;


CREATE TABLE meal_completions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  member_id   VARCHAR(50) NOT NULL,
  meal_id     VARCHAR(50) NOT NULL,
  done_on     DATE NOT NULL,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_meal (member_id, meal_id, done_on)
) ENGINE=InnoDB;


CREATE TABLE workout_plans (
  id          VARCHAR(50) PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  description TEXT,
  price       DECIMAL(8,2)
) ENGINE=InnoDB;


CREATE TABLE workout_days (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  plan_id     VARCHAR(50) NOT NULL,
  day_name    VARCHAR(15) NOT NULL,              -- Monday ... Sunday
  is_rest_day TINYINT(1) DEFAULT 0,
  FOREIGN KEY (plan_id) REFERENCES workout_plans(id) ON DELETE CASCADE,
  UNIQUE KEY one_row_per_day (plan_id, day_name)
) ENGINE=InnoDB;


CREATE TABLE exercises (
  id          VARCHAR(50) PRIMARY KEY,
  day_id      INT NOT NULL,
  name        VARCHAR(120) NOT NULL,
  sets_label  VARCHAR(50),                       -- free text, e.g. "4 Sets x 8 Reps"
  tutorial    TEXT,
  youtube_url VARCHAR(255),
  gif_path    VARCHAR(255),                      -- demo animation, stored in uploads/exercises/
  gif_credit  VARCHAR(255),                      -- source, goes in the report references
  sort_order  INT DEFAULT 0,
  FOREIGN KEY (day_id) REFERENCES workout_days(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- Steps and precautions are ordered lists shown as <ol>/<ul>, so they get their
-- own tables instead of being crammed into one newline-separated text column.
CREATE TABLE exercise_steps (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  exercise_id VARCHAR(50) NOT NULL,
  step_no     INT NOT NULL,
  body        TEXT NOT NULL,
  FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE exercise_precautions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  exercise_id VARCHAR(50) NOT NULL,
  sort_order  INT NOT NULL,
  body        TEXT NOT NULL,
  FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE diet_plans (
  id          VARCHAR(50) PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  description TEXT
) ENGINE=InnoDB;


CREATE TABLE diet_meals (
  id          VARCHAR(50) PRIMARY KEY,
  plan_id     VARCHAR(50) NOT NULL,
  name        VARCHAR(120) NOT NULL,             -- "Meal 1: Breakfast"
  description TEXT,
  day_name    VARCHAR(15),                       -- Monday ... Sunday, or NULL for every day
  sort_order  INT DEFAULT 0,
  FOREIGN KEY (plan_id) REFERENCES diet_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE announcements (
  id          VARCHAR(50) PRIMARY KEY,
  posted_on   DATE NOT NULL,
  author      VARCHAR(100),
  title       VARCHAR(200) NOT NULL,
  content     TEXT,
  important   TINYINT(1) DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


CREATE TABLE notifications (
  id          VARCHAR(50) PRIMARY KEY,
  member_id   VARCHAR(50),                       -- null = broadcast to everyone
  title       VARCHAR(200) NOT NULL,
  message     TEXT,
  kind        ENUM('warning','info','success','motivational','admin_alert') DEFAULT 'info',
  is_read     TINYINT(1) DEFAULT 0,
  for_admin   TINYINT(1) DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  INDEX idx_audience (for_admin, is_read)
) ENGINE=InnoDB;


CREATE TABLE plan_requests (
  id                  VARCHAR(50) PRIMARY KEY,
  member_id           VARCHAR(50) NOT NULL,
  member_name         VARCHAR(100),
  plan_type           ENUM('workout','diet') DEFAULT 'workout',
  current_plan_name   VARCHAR(120),
  requested_plan_id   VARCHAR(50),               -- so approval can assign it without matching on name
  requested_plan_name VARCHAR(120),
  status              ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- Single-row settings table. The admin settings screen edits this.
CREATE TABLE gym_config (
  id           TINYINT PRIMARY KEY DEFAULT 1,
  upi_id       VARCHAR(100) DEFAULT 'pay@singh-fitness',
  qr_code_url  VARCHAR(500) DEFAULT '',            -- payment QR image, uploaded by the owner
  logo_url     VARCHAR(500) DEFAULT '',            -- gym logo, uploaded by the owner. blank = built-in logo
  attend_token VARCHAR(100) DEFAULT 'singh-fitness-gym-attend-v1',
  CHECK (id = 1)
) ENGINE=InnoDB;

INSERT INTO gym_config (id) VALUES (1);


-- ---------------------------------------------------------------
-- Added for the assessment requirements: feedback, bookings, search.
-- ---------------------------------------------------------------

CREATE TABLE feedback (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  member_id   VARCHAR(50),                       -- null if a visitor submits it
  name        VARCHAR(100),
  email       VARCHAR(150),
  subject     VARCHAR(200),
  message     TEXT NOT NULL,
  rating      TINYINT,                           -- 1 to 5, optional
  seen_by_admin TINYINT(1) DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- "Booking/confirmation of events" from the brief. A member reserves a slot
-- with a trainer, the owner confirms or rejects it.
CREATE TABLE bookings (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  member_id    VARCHAR(50) NOT NULL,
  session_type ENUM('Personal Training','Diet Consultation','Body Measurement') NOT NULL,
  booking_date DATE NOT NULL,
  time_slot    VARCHAR(30) NOT NULL,             -- "07:00 - 08:00"
  status       ENUM('Pending','Confirmed','Rejected','Completed') DEFAULT 'Pending',
  note         TEXT,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  -- one member cannot hold the same slot twice
  UNIQUE KEY no_double_booking (member_id, booking_date, time_slot),
  INDEX idx_pending (status, booking_date)
) ENGINE=InnoDB;


-- One row each time points are given. The unique key means a member can only
-- get each kind of bonus once per day, however many times they tick and untick.
CREATE TABLE points_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  member_id   VARCHAR(50) NOT NULL,
  reason      VARCHAR(30) NOT NULL,              -- 'checkin', 'exercises', 'meals'
  points      INT NOT NULL,
  awarded_on  DATE NOT NULL,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  UNIQUE KEY once_per_day (member_id, reason, awarded_on)
) ENGINE=InnoDB;


-- Keyword search covers member names and plan names. FULLTEXT keeps the query
-- simple instead of chaining LIKE across columns.
ALTER TABLE members       ADD FULLTEXT KEY ft_member (name, member_code, phone);
ALTER TABLE workout_plans ADD FULLTEXT KEY ft_workout (name, description);
ALTER TABLE diet_plans    ADD FULLTEXT KEY ft_diet (name, description);

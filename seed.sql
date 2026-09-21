-- Singh Fitness Gym - sample data
-- Import this AFTER schema.sql. Gives the site an admin login, one workout
-- plan, one diet plan and a couple of announcements so pages aren't empty.

USE singh_fitness;


-- Admin login:  admin@singh-fitness.com  /  Admin@1234
-- Security answer for recovery is "gym"
INSERT INTO members (id, member_code, name, email, password_hash, phone,
    security_question, security_answer_hash, status, role, joined_date)
VALUES ('mem_admin01', 'SFG-0001', 'Gym Admin', 'admin@singh-fitness.com',
    '$2y$10$GcYi5.JyiBy/1nhZx9MlDOizfYIIjY86qmGW3Rd7f2.2L0GjGpn.u', '0400000000',
    'What was the name of your first pet?',
    '$2y$10$XzPtNaf.UNiRUt9/gbmGg.OnbaJ18rJyWvsjtmdNreFiBjuaPF8f.',
    'Active', 'admin', CURDATE());


-- ---------------- workout plan ----------------

INSERT INTO workout_plans (id, name, description, price) VALUES
('wp_beginner', 'Beginner Full Body', 'Three strength days and two light cardio days. Good starting point for the first two months.', 0);

INSERT INTO workout_days (plan_id, day_name, is_rest_day) VALUES
('wp_beginner', 'Monday',    0),
('wp_beginner', 'Tuesday',   0),
('wp_beginner', 'Wednesday', 0),
('wp_beginner', 'Thursday',  1),
('wp_beginner', 'Friday',    0),
('wp_beginner', 'Saturday',  0),
('wp_beginner', 'Sunday',    1);

-- exercises are keyed by the day row, so look the ids up rather than hard-coding them
INSERT INTO exercises (id, day_id, name, sets_label, tutorial, sort_order) VALUES
('ex_mon_1', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Monday'), 'Squats',          '3 Sets x 12 Reps', 'Feet shoulder width apart, sit back like you are sitting into a chair.', 1),
('ex_mon_2', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Monday'), 'Push Ups',        '3 Sets x 10 Reps', 'Keep your body in a straight line from head to heels.', 2),
('ex_mon_3', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Monday'), 'Plank',           '3 x 30 seconds',   'Elbows under shoulders, squeeze your glutes.', 3),

('ex_tue_1', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Tuesday'), 'Treadmill Walk', '20 minutes',       'Brisk pace, slight incline if you can.', 1),
('ex_tue_2', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Tuesday'), 'Cycling',        '15 minutes',       'Moderate resistance, keep the cadence steady.', 2),

('ex_wed_1', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Wednesday'), 'Lunges',        '3 Sets x 10 each leg', 'Step forward and drop the back knee towards the floor.', 1),
('ex_wed_2', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Wednesday'), 'Dumbbell Rows', '3 Sets x 12 Reps', 'Pull the dumbbell to your hip, not your shoulder.', 2),
('ex_wed_3', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Wednesday'), 'Glute Bridges', '3 Sets x 15 Reps', 'Drive through your heels and squeeze at the top.', 3),

('ex_fri_1', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Friday'), 'Deadlifts',        '3 Sets x 8 Reps',  'Flat back, bar close to your shins.', 1),
('ex_fri_2', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Friday'), 'Shoulder Press',   '3 Sets x 10 Reps', 'Press straight up, do not arch your lower back.', 2),
('ex_fri_3', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Friday'), 'Mountain Climbers','3 x 30 seconds',   'Keep your hips low and drive the knees in fast.', 3),

('ex_sat_1', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Saturday'), 'Rowing Machine', '15 minutes',       'Legs, then body, then arms. Reverse on the way back.', 1),
('ex_sat_2', (SELECT id FROM workout_days WHERE plan_id='wp_beginner' AND day_name='Saturday'), 'Stretching',     '10 minutes',       'Hold each stretch for 20 to 30 seconds.', 2);

INSERT INTO exercise_steps (exercise_id, step_no, body) VALUES
('ex_mon_1', 1, 'Stand with feet shoulder width apart, toes slightly out.'),
('ex_mon_1', 2, 'Push your hips back and bend your knees until thighs are parallel to the floor.'),
('ex_mon_1', 3, 'Drive through your heels to stand back up.'),
('ex_mon_2', 1, 'Start in a high plank with hands under your shoulders.'),
('ex_mon_2', 2, 'Lower your chest to just above the floor.'),
('ex_mon_2', 3, 'Press back up without letting your hips sag.');

INSERT INTO exercise_precautions (exercise_id, sort_order, body) VALUES
('ex_mon_1', 1, 'Do not let your knees cave inwards.'),
('ex_mon_1', 2, 'Stop if you feel pain in your lower back.'),
('ex_fri_1', 1, 'Ask a trainer to check your form before adding weight.');


-- ---------------- diet plan ----------------

INSERT INTO diet_plans (id, name, description) VALUES
('dp_balanced', 'Balanced Maintenance', 'Around 2200 calories spread over four meals. Suits general fitness goals.');

INSERT INTO diet_meals (id, plan_id, name, description, sort_order) VALUES
('meal_1', 'dp_balanced', 'Meal 1: Breakfast', 'Oats with milk, one banana and a handful of almonds.', 1),
('meal_2', 'dp_balanced', 'Meal 2: Lunch',     'Grilled chicken or paneer, brown rice and mixed vegetables.', 2),
('meal_3', 'dp_balanced', 'Meal 3: Snack',     'Greek yoghurt with berries, or a protein shake after training.', 3),
('meal_4', 'dp_balanced', 'Meal 4: Dinner',    'Dal, two rotis and a large salad. Keep it light and early.', 4);


-- ---------------- announcements ----------------

INSERT INTO announcements (id, posted_on, author, title, content, important) VALUES
('ann_001', CURDATE(), 'Gym Admin', 'Welcome to the new member portal', 'You can now check your workout, log water and sleep, and track your progress from here. Scan the QR at reception every visit to keep your streak going.', 0),
('ann_002', CURDATE(), 'Gym Admin', 'Public holiday hours', 'The gym will open from 7am to 12pm on the next public holiday. Evening shift is cancelled that day.', 1);


-- ---------------- tutorial videos ----------------
-- youtube links go in the report references as the source of each demo

UPDATE exercises SET youtube_url = 'https://www.youtube.com/watch?v=otzWCWpuW-A', gif_credit = 'ATHLEAN-X on YouTube' WHERE id = 'ex_mon_1';
UPDATE exercises SET youtube_url = 'https://www.youtube.com/watch?v=WDIpL0pjun0', gif_credit = 'NASM on YouTube'      WHERE id = 'ex_mon_2';
UPDATE exercises SET youtube_url = 'https://www.youtube.com/watch?v=pvIjsG5Svck', gif_credit = 'Children''s Hospital Colorado on YouTube' WHERE id = 'ex_mon_3';
UPDATE exercises SET youtube_url = 'https://www.youtube.com/watch?v=ntr64W6ZWB0', gif_credit = 'YouTube'              WHERE id = 'ex_fri_1';


-- ---------------- second workout plan ----------------
-- so members have something to request a change to

INSERT INTO workout_plans (id, name, description, price) VALUES
('wp_strength', 'Intermediate Strength', 'Four heavier lifting days with one cardio day. For members past their first two months.', 0);

INSERT INTO workout_days (plan_id, day_name, is_rest_day) VALUES
('wp_strength', 'Monday',    0),
('wp_strength', 'Tuesday',   0),
('wp_strength', 'Wednesday', 1),
('wp_strength', 'Thursday',  0),
('wp_strength', 'Friday',    0),
('wp_strength', 'Saturday',  0),
('wp_strength', 'Sunday',    1);

INSERT INTO exercises (id, day_id, name, sets_label, tutorial, youtube_url, gif_credit, sort_order) VALUES
('ex_st_mon_1', (SELECT id FROM workout_days WHERE plan_id='wp_strength' AND day_name='Monday'), 'Barbell Squats',   '4 Sets x 8 Reps',  'Bar on the upper back, brace your core before each rep.', 'https://www.youtube.com/watch?v=otzWCWpuW-A', 'ATHLEAN-X on YouTube', 1),
('ex_st_mon_2', (SELECT id FROM workout_days WHERE plan_id='wp_strength' AND day_name='Monday'), 'Leg Press',        '3 Sets x 12 Reps', 'Feet shoulder width on the plate, do not lock your knees at the top.', NULL, NULL, 2),
('ex_st_tue_1', (SELECT id FROM workout_days WHERE plan_id='wp_strength' AND day_name='Tuesday'), 'Bench Press',     '4 Sets x 8 Reps',  'Shoulder blades pinched, bar to mid chest.', NULL, NULL, 1),
('ex_st_tue_2', (SELECT id FROM workout_days WHERE plan_id='wp_strength' AND day_name='Tuesday'), 'Push Ups',        '3 Sets x 15 Reps', 'Finish with a burnout set.', 'https://www.youtube.com/watch?v=WDIpL0pjun0', 'NASM on YouTube', 2),
('ex_st_thu_1', (SELECT id FROM workout_days WHERE plan_id='wp_strength' AND day_name='Thursday'), 'Deadlifts',      '4 Sets x 6 Reps',  'Heavier than the beginner plan, rest two minutes between sets.', 'https://www.youtube.com/watch?v=ntr64W6ZWB0', 'YouTube', 1),
('ex_st_thu_2', (SELECT id FROM workout_days WHERE plan_id='wp_strength' AND day_name='Thursday'), 'Pull Ups',       '3 Sets x max',     'Use the assisted machine if you cannot do five yet.', NULL, NULL, 2),
('ex_st_fri_1', (SELECT id FROM workout_days WHERE plan_id='wp_strength' AND day_name='Friday'), 'Overhead Press',   '4 Sets x 8 Reps',  'Squeeze your glutes so your lower back does not arch.', NULL, NULL, 1),
('ex_st_fri_2', (SELECT id FROM workout_days WHERE plan_id='wp_strength' AND day_name='Friday'), 'Plank',            '3 x 60 seconds',   'Longer holds than the beginner plan.', 'https://www.youtube.com/watch?v=pvIjsG5Svck', 'Children''s Hospital Colorado on YouTube', 2),
('ex_st_sat_1', (SELECT id FROM workout_days WHERE plan_id='wp_strength' AND day_name='Saturday'), 'Interval Run',   '20 minutes',       'One minute fast, one minute slow, repeat.', NULL, NULL, 1);


-- ---------------- day-specific meals ----------------
-- meals with no day_name apply every day. these add variety on certain days
-- so the weekly diet view actually differs from day to day.

INSERT INTO diet_meals (id, plan_id, name, description, day_name, sort_order) VALUES
('meal_mon_x', 'dp_balanced', 'Post-workout shake', 'Whey protein with water, straight after your Monday session.', 'Monday',    5),
('meal_wed_x', 'dp_balanced', 'Post-workout shake', 'Whey protein with water, straight after your Wednesday session.', 'Wednesday', 5),
('meal_fri_x', 'dp_balanced', 'Post-workout shake', 'Whey protein with water, straight after your Friday session.', 'Friday',    5),
('meal_sat_x', 'dp_balanced', 'Extra carbs',        'Add a sweet potato or extra rice at lunch, you trained hard this week.', 'Saturday', 5),
('meal_sun_x', 'dp_balanced', 'Free meal',          'One meal of your choice. Enjoy it, then back on plan Monday.', 'Sunday',    5);


-- ---------------- second diet plan ----------------
-- so members have a diet to request a change to as well

INSERT INTO diet_plans (id, name, description) VALUES
('dp_cut', 'High Protein Cut', 'Around 1800 calories, protein at every meal. For members trying to lose fat while keeping muscle.');

INSERT INTO diet_meals (id, plan_id, name, description, day_name, sort_order) VALUES
('cut_1', 'dp_cut', 'Meal 1: Breakfast', 'Egg whites with spinach, one slice of wholegrain toast.', NULL, 1),
('cut_2', 'dp_cut', 'Meal 2: Lunch',     'Grilled fish or tofu with a large salad, no dressing.', NULL, 2),
('cut_3', 'dp_cut', 'Meal 3: Snack',     'Protein shake with water, or a small tub of cottage cheese.', NULL, 3),
('cut_4', 'dp_cut', 'Meal 4: Dinner',    'Chicken or paneer with steamed vegetables. No rice or roti.', NULL, 4),
('cut_sun', 'dp_cut', 'Refeed meal',     'One higher carb meal on Sunday to keep energy up for the week.', 'Sunday', 5);

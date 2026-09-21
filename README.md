# Singh Fitness Gym

Gym management website for ICT101 Assessment 2. Plain PHP and MySQL on XAMPP.
No frameworks or templates. Two stylesheets (assets/css/base.css and shared.css).

## How to run

1. Copy this folder to `D:\xampp\htdocs\singh-fitness` (or your htdocs folder).
2. Start Apache and MySQL in the XAMPP Control Panel.
3. In phpMyAdmin, import `schema.sql` first, then `seed.sql`.
4. Open http://localhost/singh-fitness/

`config.php` connects as `root` with no password, which is the XAMPP default.

## Logins

| Who   | Email                    | Password   |
|-------|--------------------------|------------|
| Owner | admin@singh-fitness.com  | Admin@1234 |

Members sign up on the Register page. The owner approves them on the
Dashboard before they can log in.

Check-in code for the scanner page (or type it in): `singh-fitness-gym-attend-v1`

## Pages

Public: index, register, login, forgot-password, feedback
Member: home, fitness, exercise, progress, profile, scanner, bookings, search
Owner: admin-dashboard, admin-members, admin-plans, admin-settings

## Media sources

Exercise tutorial videos are embedded from YouTube. The channel for each
one is stored with the exercise (`exercises.gif_credit`) and shown under the video.

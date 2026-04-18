# Genesis Attendance

## Overview
A WordPress plugin for manual daily attendance tracking. Admins record visitor counts via a shortcode-rendered form, and the data is stored in a dedicated database table for historical reporting.

## Problem It Solves
- WordPress sites with physical venues (community halls, clubs, gyms) need a lightweight way to log daily visitor counts without a third-party integration
- Target users: WordPress site admins who manually record attendance at the end of each day

## Use Cases
1. A community center admin visits a protected page each evening and enters the visitor count for the day
2. An event organizer reviews historical attendance to compare turnout across weeks
3. A site admin corrects a previously entered value by resubmitting the form for that date

## Key Features
- `[attendance_form]` shortcode — drop the form onto any page in seconds
- Admin-only access — the form is invisible to non-admins, no extra role configuration needed
- Duplicate-safe — uses a `DATE PRIMARY KEY` so resubmitting for the same date updates rather than duplicates
- Nonce-verified submissions to prevent CSRF

## Tech Stack
- PHP 7.0+
- WordPress 5.0+
- MySQL (via `$wpdb` — no external dependencies)

## Getting Started

1. Copy the `genesis-wp-admin` folder to `wp-content/plugins/`
2. Activate **Genesis Attendance** in the WordPress admin under **Plugins**
3. Add the shortcode to any page:
   ```
   [attendance_form]
   ```
4. Log in as an admin and visit the page to start recording attendance

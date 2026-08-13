Harddisk Delivery - Sarabun Font Patch
======================================

Purpose
- Change Harddisk Delivery UI font to Sarabun, matching Serial Computer.
- No PHP business logic, database logic, session logic, layout sizing, or component dimensions are changed.

Copy these files to the same paths in the application:
- includes/header.php
- assets/css/hdd-sarabun-font.css
- assets/css/app.css
- assets/css/hdd-fonts.css
- public/login.php

Important
- This patch intentionally does NOT include font binary files.
- Keep the existing directory assets/fonts/sarabun/ on the server.
- Required existing files include Sarabun-Regular.ttf, Sarabun-Medium.ttf, Sarabun-SemiBold.ttf, Sarabun-Bold.ttf, etc.

After upload
- Hard refresh browser: Ctrl+F5
- If a reverse proxy/CDN caches CSS, purge the CSS cache once.

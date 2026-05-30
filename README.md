# Information System Kiosk Display

A web-based kiosk display system built with PHP, designed for institutional use. It features an admin panel for managing content and a display interface for presenting information on a kiosk screen.

> Originally developed during an internship. Adapted and expanded from an existing codebase with additional modules for doctors, departments, user management, and display control.

---

## Features

- **Doctor Management** — Add, edit, and manage doctor profiles shown on the kiosk
- **Department Management** — Manage hospital/institution departments
- **Display Control** — Control what content appears on the kiosk screen in real time
- **User Management** — Admin account management
- **Password Management** — Secure credential updates for admin users
- **Authentication** — Login/logout system for admin access

---

## Project Structure

```
/
├── admin/
│   ├── index.php                  # Admin entry point
│   ├── login.php                  # Login page
│   └── logout.php                 # Logout handler
├── config/                        # Database and app configuration
└── display/                       # Public-facing kiosk display interface
    └── index.php                  # Public entry point

---

## Tech Stack

- **Backend:** PHP
- **Frontend:** HTML, CSS, JavaScript (AJAX)
- **Database:** MySQL (via config/)

---

## Requirements

- PHP 7.4+
- MySQL
- Apache (XAMPP or similar local server)
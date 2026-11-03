# Belle Salon – Salon Appointment and Management System (web version)

A simple, multi-page website (PHP + MySQL) that implements the UML design of the SENG8323 Salon project:
clients book appointments, stylists run their day, the admin manages services, shifts, staff and reports.

## Run it with XAMPP (5 steps)
1. Install XAMPP and open the **XAMPP Control Panel**. Start **Apache** and **MySQL**.
2. Copy this whole folder into `C:\xampp\htdocs\` so you get `C:\xampp\htdocs\salon\`  (Mac/Linux: `htdocs/salon`).
3. Open <http://localhost/phpmyadmin> → **Import** → choose `database/salon.sql` → **Go**. This creates the database `salon_db` with demo data.
4. Open <http://localhost/salon/>.
5. Log in as the admin, go to **Schedules → "Set working hours"** (pick a stylist, a date range, the weekdays and the hours: no need to enter day by day) (stylists can only be booked inside a shift).

If your MySQL has a password, edit `includes/config.php` (DB_USER / DB_PASS).

## Demo accounts (password for all: `Salon@123`)
| Role | E-mail |
|---|---|
| Admin | admin@salon.rw |
| Stylist | grace@salon.rw, olive@salon.rw (Hair), peter@salon.rw (Nails) |
| Client | claire@mail.rw, david@mail.rw |

New clients can also register on the **Register** page.
> Before putting it online: delete the "demo accounts" box in `login.php` and change the demo passwords.

## Pages
| Who | Page | What it does |
|---|---|---|
| Everyone | `index.php`, `login.php`, `register.php` | Home with services, log in, sign up |
| Client | `client/dashboard.php` | Upcoming / past appointments, loyalty points |
| | `client/book.php` | Choose a service, then SEE every matching stylist with their free times: a 14-day strip shows how many free times each day has; one click on a time books it |
| | `client/reschedule.php`, `pay.php`, `review.php` | Move a booking, pay (promo codes), rate the visit |
| Stylist | `staff/schedule.php` | Day view, **Check in** client, **Complete** service |
| Admin | `admin/dashboard.php` | Report: appointments by status, revenue, ratings |
| | `admin/appointments.php` | Filter, reassign stylist, cancel |
| | `admin/services.php`, `schedules.php`, `staff.php` | Manage services, work shifts (by date range and weekdays, plus days off), stylist accounts |
| All | `notifications.php` | In-app notifications |

## How the code follows the design
- **Class diagram** → tables `users, clients, staff, admins, services, staff_schedule, appointments, payments, notifications, reviews` (the ERD).
- **State diagram** → `STATUS_FLOW` in `includes/functions.php`; every status change goes through `change_status()`:
  requested → confirmed / rejected; confirmed → in_progress / cancelled; in_progress → completed.
- **Activity diagram** → `client/book.php` (shows free times; the availability decision is re-checked on click), `staff/schedule.php` (arrived on time? else no-show after a 15-minute grace period), `pay.php`.
- **Use cases** → one page per use case, guarded by role (`require_role()`).
- Rules: a stylist can't be double-booked or booked outside a shift; one payment and one review per appointment.
- **Payment:** a client can pay as soon as the appointment is confirmed (pay in advance) or after the service. Cash, card or mobile money.
- **Loyalty:** 1 point per 1,000 RWF paid (at least 1 per payment). At **20 points** (`LOYAL_POINTS` in `includes/config.php`) the client becomes a **Loyal client** and unlocks `LOYAL20` (20% off). `WELCOME10` (10% off) works on the first payment only. The client dashboard shows a progress bar.
- **Rating:** after a service is *completed* the client sees a **Review** button (1 to 5 stars). The stylist completes it from "My schedule", or the admin can press **Mark done** on Admin → Appointments.
- **Admin dashboard:** the default period is the 1st of this month up to 60 days ahead (so future bookings count), with "This month / Next 30 days / All time" buttons, revenue (by payment date) by service and stylist, "Most booked services", and a "Latest bookings" table.

## Security basics included
Passwords hashed (`password_hash`), prepared statements (no SQL injection), output escaping (`h()`), CSRF token on every form, role checks on every page, simple login throttle.

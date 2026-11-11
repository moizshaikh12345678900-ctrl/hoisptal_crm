# Hospital & Clinic CRM — Core PHP + MySQL

A starter implementation of a role-based hospital/clinic CRM for local development with XAMPP. Built with Core PHP (no Laravel), PDO, MySQL, Bootstrap 5 and vanilla JavaScript.

## Requirements
- XAMPP with PHP 8.1+ recommended (PDO MySQL enabled)
- MySQL/MariaDB and phpMyAdmin
- Internet connection for Bootstrap and Bootstrap Icons CDN (download assets locally if offline use is required)

## Install on Windows / XAMPP
1. Extract the ZIP.
2. Copy the `hospital-crm` folder to `C:\xampp\htdocs\hospital-crm`.
3. Start **Apache** and **MySQL** in XAMPP Control Panel.
4. Open `http://localhost/phpmyadmin`.
5. Create a NEW empty database named `hospital_crm` with collation `utf8mb4_unicode_ci`.
6. Select `hospital_crm` → **Import** → choose `schema.sql` from the project root → **Import/Go**. If you already have the older project database, import `upgrade_assign_doctor.sql` instead (one time) to add doctor assignment without deleting existing records.
   - Import this schema only into a fresh empty database. It does not drop existing tables.
7. Open `config/database.php` and confirm host/database/user/password match your XAMPP settings. Defaults: `localhost`, `hospital_crm`, `root`, empty password.
8. Visit `http://localhost/hospital-crm/`.

## Updating an existing installation

- Back up your project folder and MySQL database first.
- Copy the updated project files over the existing `hospital-crm` folder.
- The added invoice, invoice-print, schedule and department screens reuse tables already present in this package's schema; no new SQL table is required for these changes.
- Do not re-import `schema.sql` into a live database. For an older database, compare it with the current schema and apply only the required upgrade statements after making a backup.

## Demo sign-in accounts
All demo accounts use password: `password123`.

| Role | Email |
|---|---|
| Super Admin | `superadmin@apexhealth.com` |
| Admin | `admin@apexhealth.com` |
| Doctor | `dr.sarah@apexhealth.com` |
| Doctor | `dr.ahmed@apexhealth.com` |
| Staff / Reception | `reception@apexhealth.com` |
| Patient | `robert.patient@gmail.com` |
| Visitor / Guest | Open the public home page; no login needed |

**Important:** Change or remove demo accounts before deploying publicly. Demo patient data is fictional.

## Implemented areas
- Role-based login, session handling, CSRF tokens and server-side role guards
- Super-admin user creation and activation/deactivation
- Doctor directory and profile creation through user management
- Patient registration, directory and search; staff can assign each new patient to an active doctor, and the patient appears in that doctor’s dashboard
- Appointment booking, overlap prevention, unique slot constraints and status updates; staff bookings are assigned to the selected doctor and appear in that doctor’s schedule
- Doctor appointment detail pages with patient profile, allergies, appointment information and available clinical history
- Doctor consultation notes and one-item prescription entry per consultation
- Patient portal for appointments, released medical records and invoices
- Automatic consultation invoices on appointment completion, manual invoices, partial/full payment recording, payment history and A4 print/PDF invoice views
- Doctor-scoped invoice register and patient invoice history with server-side ownership checks
- Weekly doctor schedule management and schedule-aware appointment slot selection / server-side validation
- Department directory management and activation controls
- Clinic reports, settings, audit logs and public contact/appointment inquiries
- Responsive Bootstrap UI with shared navigation and layout

## Notes / known limitations
- This is a development starter, not a certified or production-audited electronic health record system.
- Doctors with configured weekly schedules can only be booked inside their configured hours and aligned slot lengths. Doctors without schedule rows use the clinic default slot setting and demo hours (08:00–18:00).
- Notifications are in-app/audit-oriented; email, SMS, WhatsApp and payment-gateway integrations are not configured.
- Prescription entry currently accepts one medicine item per consultation; multi-item prescription authoring is a planned enhancement.
- Password reset and invitation delivery need a configured email service before production use.
- Before using real patient data, perform security review, backup/restore testing, access-control testing, HTTPS deployment and local legal/privacy compliance checks.

## Security basics in this package
- Passwords stored with `password_hash()` and checked with `password_verify()`
- PDO prepared statements for database writes and filtered queries
- CSRF tokens on state-changing forms
- Role checks on protected pages and patient/doctor ownership checks on core clinical views
- HTML output escaped with `htmlspecialchars()`
- Audit logging for selected sensitive actions

## Troubleshooting
- **Database connection failed:** Start MySQL and check `config/database.php`.
- **Unknown column full_name/email/phone:** Ensure you imported this package's `schema.sql` into a new database, not an older schema.
- **CSS or links fail:** Keep the project folder named `hospital-crm` under `htdocs`; `BASE_URL` is derived from the document root.
- **Login fails:** Use the demo emails and `password123`; ensure all SQL import statements completed successfully.


## English / French language support
- Use the language selector in the top navigation to switch between English and French.
- The selected language is saved in the current browser and restored on the next visit.
- Translation dictionaries are stored in `languages/en.json` and `languages/fr.json`.
- The translation engine is `assets/js/language.js`; keep translation keys aligned in both JSON files when adding new interface text.
- This is interface translation; patient-entered content, doctor names, clinic settings, and other user/database content remain as entered.

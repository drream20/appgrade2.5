# Registrar Interface

Registrar module for viewing class lists, and downloading TOR (Transcript of Record) and Prospectus.

## Setup

1. **Run the migration** (in phpMyAdmin or MySQL CLI):
   ```
   source c:/xampp/htdocs/mysystem/registrar/database/migration_registrar.sql
   ```
   Or copy and paste the contents of `database/migration_registrar.sql` into phpMyAdmin SQL tab.

2. **Access the Registrar interface**:
   ```
   http://localhost/mysystem/registrar/
   ```

3. **Default login**:
   - Email: `registrar@test.com`
   - Password: `password`

## Features

- **Class List**: View enrolled students per subject across all instructors
- **Students**: Search students and download documents
- **TOR (Transcript of Record)**: CSV download of student grades across all subjects
- **Prospectus**: CSV download of curriculum (1st–4th year subjects) with Taken/Not Taken status

No schedule viewing as per requirements.

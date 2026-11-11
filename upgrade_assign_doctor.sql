-- Apply this ONE TIME to an existing hospital_crm database that was created before doctor assignment was added.
-- If you imported the updated schema.sql into a brand-new database, do NOT run this file.
ALTER TABLE patients
  ADD COLUMN assigned_doctor_id INT NULL AFTER user_id,
  ADD KEY idx_patients_assigned_doctor (assigned_doctor_id),
  ADD CONSTRAINT fk_patients_assigned_doctor
    FOREIGN KEY (assigned_doctor_id) REFERENCES doctors(id) ON DELETE SET NULL;

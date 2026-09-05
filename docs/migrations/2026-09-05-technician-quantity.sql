ALTER TABLE atlas_rental_enquiries
    ADD COLUMN IF NOT EXISTS technician_quantity TINYINT UNSIGNED NULL AFTER technician_required;

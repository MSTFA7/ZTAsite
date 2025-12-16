USE zta_site;

-- table el dacatra
 
CREATE TABLE IF NOT EXISTS doctors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- table el nas el ta3bana

CREATE TABLE IF NOT EXISTS patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    birth_date DATE NOT NULL,
    gender ENUM('M', 'F', 'Other') DEFAULT 'Other',
    medical_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE RESTRICT
);
-- el dacatra wel admin

INSERT INTO doctors (username, full_name, email) 
VALUES 
    ('user1', 'Dr. One', 'user1@emailcom'),
    ('user2', 'Dr. Two', 'user2@email.com'),
    ('admin1', 'Admin User', 'admin@email.com');
    
    
-- el nas el t3bana

INSERT INTO patients (doctor_id, full_name, birth_date, gender, medical_notes) 
VALUES 
    (1, 'Alice Brown', '1985-03-15', 'F', 'Allergic to penicillin'),
    (1, 'Bob Johnson', '1990-07-22', 'M', 'Chronic back pain'),
    (2, 'Charlie Davis', '1978-11-30', 'M', 'Asthma'),           
    (2, 'Diana Lee', '1995-05-10', 'F', 'No known allergies'); 
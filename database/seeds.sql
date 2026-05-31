-- Admin user
INSERT INTO users (username, email, password, phone, role, is_active)
VALUES (
    'admin',
    'admin@medlan.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '07501234567',
    'admin',
    1
);

-- Default colors
INSERT INTO colors (name, hexa_number) VALUES
('سوور', '#FF0000'),
('شین', '#0000FF'),
('ڕەش', '#000000'),
('سپی', '#FFFFFF'),
('سەوز', '#00FF00'),
('زەرد', '#FFFF00');

-- Default sizes
INSERT INTO sizes (name) VALUES
('Small'),
('Medium'),
('Large'),
('X-Large'),
('XX-Large');


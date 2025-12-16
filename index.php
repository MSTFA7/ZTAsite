<?php
require __DIR__ . '/vendor/autoload.php';

use Jumbojett\OpenIDConnectClient;

// Start session
session_start();

// MySQL connection
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=zta_site',
        'root',
        'Main1234@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Database connection failed: ' . htmlspecialchars($e->getMessage());
    exit;
}

// Keycloak configuration
$oidc = new OpenIDConnectClient(
    'http://10.0.0.134:8080/realms/ZTAsite',
    'static-site-client',
    'k8IwaEPnJicnVKQeyXfSupDomgyC0krK'
);
$oidc->setRedirectURL('http://10.0.0.130/ZTAsite/index.php');

// Handle logout
if (isset($_GET['logout'])) {
    $redirect_uri = 'http://10.0.0.130/ZTAsite/index.php';
    if (isset($_SESSION['oidc_id_token'])) {
        try {
            $logout_endpoint = 'http://10.0.0.134:8080/realms/ZTAsite/protocol/openid-connect/logout';
            $params = http_build_query([
                'id_token_hint' => $_SESSION['oidc_id_token'],
                'post_logout_redirect_uri' => $redirect_uri
            ]);
            $logout_url = $logout_endpoint . '?' . $params;
            session_unset();
            session_destroy();
            header('Location: ' . $logout_url);
            exit;
        } catch (Exception $e) {
            error_log('Logout error: ' . $e->getMessage());
            session_unset();
            session_destroy();
        }
    } else {
        error_log('Logout error: No ID token found in session');
        session_unset();
        session_destroy();
    }
    header('Location: ' . $redirect_uri);
    exit;
}

// Handle callback
if (isset($_GET['code'])) {
    try {
        $oidc->authenticate();
        $userInfo = $oidc->requestUserInfo();

        // Determine full_name from Keycloak, fallback to preferred_username
        $full_name = $userInfo->name ?? $userInfo->preferred_username ?? 'Unknown User';

        // Store user info in session
        $_SESSION['oidc_id_token'] = $oidc->getIdToken();
        $_SESSION['user'] = [
            'username' => $userInfo->preferred_username ?? null,
            'full_name' => $full_name,
            'email' => $userInfo->email ?? ''
        ];

        // Sync user to doctors table
        $stmt = $pdo->prepare(
            'INSERT INTO doctors (username, full_name, email) 
             VALUES (:username, :full_name, :email) 
             ON DUPLICATE KEY UPDATE full_name = :full_name, email = :email'
        );
        $stmt->execute([
            'username' => $userInfo->preferred_username,
            'full_name' => $full_name,
            'email' => $userInfo->email ?? ''
        ]);

        header('Location: /ZTAsite/index.php');
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo 'Authentication error: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}
// Check if user is authenticated
if (!isset($_SESSION['oidc_id_token']) || !isset($_SESSION['user'])) {
    $oidc->authenticate();
    exit;
}

// Hardcoded access control
$username = $_SESSION['user']['username'];

// Fetch doctor's full name
try {
    $stmt = $pdo->prepare('SELECT full_name FROM doctors WHERE username = :username');
    $stmt->execute(['username' => $username]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doctor) {
        http_response_code(403);
        echo 'Access Denied: User not found in doctors table. <a href="?logout">Logout</a>';
        exit;
    }
    $display_name = htmlspecialchars($doctor['full_name']);
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Database error: ' . htmlspecialchars($e->getMessage());
    exit;
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Doctor: Manage patients
        if ($username === 'user1' || $username === 'user2') {
            // Add patient
            if (isset($_POST['action']) && $_POST['action'] === 'add') {
                $stmt = $pdo->prepare(
                    'INSERT INTO patients (doctor_id, full_name, birth_date, gender, medical_notes) 
                     VALUES (:doctor_id, :full_name, :birth_date, :gender, :medical_notes)'
                );
                $stmt->execute([
                    'doctor_id' => $_POST['doctor_id'],
                    'full_name' => $_POST['full_name'],
                    'birth_date' => $_POST['birth_date'],
                    'gender' => $_POST['gender'],
                    'medical_notes' => $_POST['medical_notes'] ?? null
                ]);
            }
            // Edit patient
            elseif (isset($_POST['action']) && $_POST['action'] === 'edit') {
                $stmt = $pdo->prepare(
                    'UPDATE patients 
                     SET full_name = :full_name, birth_date = :birth_date, gender = :gender, medical_notes = :medical_notes 
                     WHERE id = :id AND doctor_id = :doctor_id'
                );
                $stmt->execute([
                    'id' => $_POST['patient_id'],
                    'doctor_id' => $_POST['doctor_id'],
                    'full_name' => $_POST['full_name'],
                    'birth_date' => $_POST['birth_date'],
                    'gender' => $_POST['gender'],
                    'medical_notes' => $_POST['medical_notes'] ?? null
                ]);
            }
            // Delete patient
            elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
                $stmt = $pdo->prepare('DELETE FROM patients WHERE id = :id AND doctor_id = :doctor_id');
                $stmt->execute([
                    'id' => $_POST['patient_id'],
                    'doctor_id' => $_POST['doctor_id']
                ]);
            }
        }
        // Admin: Manage doctors
        elseif ($username === 'admin1') {
            // Add doctor
            if (isset($_POST['action']) && $_POST['action'] === 'add_doctor') {
                $stmt = $pdo->prepare(
                    'INSERT INTO doctors (username, full_name, email) 
                     VALUES (:username, :full_name, :email)'
                );
                $stmt->execute([
                    'username' => $_POST['username'],
                    'full_name' => $_POST['full_name'],
                    'email' => $_POST['email'] ?? null
                ]);
            }
            // Edit doctor
            elseif (isset($_POST['action']) && $_POST['action'] === 'edit_doctor') {
                $stmt = $pdo->prepare(
                    'UPDATE doctors 
                     SET username = :username, full_name = :full_name, email = :email 
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => $_POST['doctor_id'],
                    'username' => $_POST['username'],
                    'full_name' => $_POST['full_name'],
                    'email' => $_POST['email'] ?? null
                ]);
            }
            // Delete doctor
            elseif (isset($_POST['action']) && $_POST['action'] === 'delete_doctor') {
                $stmt = $pdo->prepare('DELETE FROM doctors WHERE id = :id');
                $stmt->execute(['id' => $_POST['doctor_id']]);
            }
        }
        header('Location: /ZTAsite/index.php');
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo 'Database error: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}

try {
    if ($username === 'admin1') {
        // Admin sees all doctors
        $stmt = $pdo->prepare('SELECT id, username, full_name, email, created_at FROM doctors');
        $stmt->execute();
        $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Admin Dashboard</title>
            <style>
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #e8e8e8; padding: 8px; text-align: left; }
                th { background-color: #4d5156; color: #e8e8e8; }
                td { color: #e8e8e8; }
                .container {
                    display: flex;
                    width: 70vw;
                    justify-content: center;
                    align-items: center;
                    flex-direction: column;
                    margin: 70px 0 0 0;
                }
                body {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    font-family: Roboto, sans-serif;
                    background: #1f1f1f;
                    color: #e8e8e8;
                }
                .header {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    background-color: #4d5156;
                    min-height: 50px;
                    display: flex;
                    justify-content: space-between;
                    box-sizing: border-box;
                    gap: 20px;
                    padding: 0 30px;
                }
                .links {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    gap: 20px;
                }
                .logo {
                    font-size: large;
                    font-weight: bold;
                    box-sizing: border-box;
                    color: #e8e8e8;
                }
                a { text-decoration: none; color: #e8e8e8; }
                .modal {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0,0,0,0.5);
                    justify-content: center;
                    align-items: center;
                }
                .modal-content {
                    background-color: #2a2a2a;
                    color: #e8e8e8;
                    padding: 20px;
                    border-radius: 10px;
                    width: 400px;
                    max-width: 90%;
                }
                .modal-content h2 {
                    margin-top: 0;
                    border-bottom: 1px solid #4d5156;
                    padding-bottom: 10px;
                }
                .modal-content label {
                    display: block;
                    margin: 10px 0 5px;
                }
                .modal-content input, .modal-content select, .modal-content textarea {
                    width: 100%;
                    padding: 8px;
                    background-color: #1f1f1f;
                    color: #e8e8e8;
                    border: 1px solid #4d5156;
                    border-radius: 5px;
                    box-sizing: border-box;
                }
                .modal-content textarea { height: 100px; resize: none; }
                .modal-buttons { margin-top: 20px; text-align: right; }
                .btn {
                    padding: 8px 16px;
                    margin-left: 10px;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    color: #e8e8e8;
                }
                .btn-primary { background-color: #4d5156; width: 70px; margin-bottom: 3px; }
                .btn-secondary { background-color: #333; width: 70px; }
                .btn-danger { background-color: #dc3545; width: 70px; }
                .btn-add { background-color: #4d5156; padding: 10px 20px; margin-bottom: 10px; }
            </style>
            <script>
                function openModal(modalId) {
                    document.getElementById(modalId).style.display = 'flex';
                }
                function closeModal(modalId) {
                    document.getElementById(modalId).style.display = 'none';
                }
                function populateEditModal(id, username, fullName, email) {
                    document.getElementById('editDoctorId').value = id;
                    document.getElementById('editUsername').value = username;
                    document.getElementById('editFullName').value = fullName;
                    document.getElementById('editEmail').value = email || '';
                    openModal('editDoctorModal');
                }
            </script>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div class="logo">
                        <p>Admin Dashboard</p>
                    </div>
                    <div class="links">
                        <a href="docs.html">Docs</a>
                        <a href="?logout">Logout</a>
                    </div>
                </div>
                <p>Welcome, <?php echo $display_name; ?>!</p>
                <button class="btn btn-add" onclick="openModal('addDoctorModal')">Add Doctor</button>
                <table>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                    <?php foreach ($doctors as $doctor): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($doctor['username']); ?></td>
                            <td><?php echo htmlspecialchars($doctor['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($doctor['email']); ?></td>
                            <td><?php echo htmlspecialchars($doctor['created_at']); ?></td>
                            <td>
                                <button class="btn btn-primary" onclick="populateEditModal('<?php echo $doctor['id']; ?>', '<?php echo htmlspecialchars($doctor['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($doctor['full_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($doctor['email'] ?? '', ENT_QUOTES); ?>')">Edit</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_doctor">
                                    <input type="hidden" name="doctor_id" value="<?php echo $doctor['id']; ?>">
                                    <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this doctor?');">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <!-- Add Doctor Modal -->
            <div id="addDoctorModal" class="modal">
                <div class="modal-content">
                    <h2>Add Doctor</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_doctor">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required>
                        <label for="fullName">Full Name</label>
                        <input type="text" id="fullName" name="full_name" required>
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email">
                        <div class="modal-buttons">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('addDoctorModal')">Close</button>
                            <button type="submit" class="btn btn-primary">Add Doctor</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Doctor Modal -->
            <div id="editDoctorModal" class="modal">
                <div class="modal-content">
                    <h2>Edit Doctor</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="edit_doctor">
                        <input type="hidden" name="doctor_id" id="editDoctorId">
                        <label for="editUsername">Username</label>
                        <input type="text" id="editUsername" name="username" required>
                        <label for="editFullName">Full Name</label>
                        <input type="text" id="editFullName" name="full_name" required>
                        <label for="editEmail">Email</label>
                        <input type="email" id="editEmail" name="email">
                        <div class="modal-buttons">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('editDoctorModal')">Close</button>
                            <button type="submit" class="btn btn-primary">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </body>
        </html>
        <?php
    } elseif ($username === 'user1' || $username === 'user2') {
        // Doctors see their patients
        $stmt = $pdo->prepare('SELECT id FROM doctors WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $doctor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doctor) {
            http_response_code(403);
            echo 'Access Denied: Doctor profile not found. <a href="?logout">Logout</a>';
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT id, full_name, birth_date, gender, medical_notes, created_at 
             FROM patients WHERE doctor_id = :doctor_id'
        );
        $stmt->execute(['doctor_id' => $doctor['id']]);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Doctor Dashboard</title>
            <style>
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #e8e8e8; padding: 8px; text-align: left; }
                th { background-color: #4d5156; color: #e8e8e8; }
                td { color: #e8e8e8; }
                .container {
                    display: flex;
                    width: 70vw;
                    justify-content: center;
                    align-items: center;
                    flex-direction: column;
                    margin: 70px 0 0 0;
                }
                body {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    font-family: Roboto, sans-serif;
                    background: #1f1f1f;
                    color: #e8e8e8;
                }
                .header {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    background-color: #4d5156;
                    min-height: 50px;
                    display: flex;
                    justify-content: space-between;
                    box-sizing: border-box;
                    gap: 20px;
                    padding: 0 30px;
                }
                .links {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    gap: 20px;
                }
                .logo {
                    font-size: large;
                    font-weight: bold;
                    box-sizing: border-box;
                    color: #e8e8e8;
                }
                a { text-decoration: none; color: #e8e8e8; }
                .modal {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0,0,0,0.5);
                    justify-content: center;
                    align-items: center;
                }
                .modal-content {
                    background-color: #2a2a2a;
                    color: #e8e8e8;
                    padding: 20px;
                    border-radius: 10px;
                    width: 400px;
                    max-width: 90%;
                }
                .modal-content h2 {
                    margin-top: 0;
                    border-bottom: 1px solid #4d5156;
                    padding-bottom: 10px;
                }
                .modal-content label {
                    display: block;
                    margin: 10px 0 5px;
                }
                .modal-content input, .modal-content select, .modal-content textarea {
                    width: 100%;
                    padding: 8px;
                    background-color: #1f1f1f;
                    color: #e8e8e8;
                    border: 1px solid #4d5156;
                    border-radius: 5px;
                    box-sizing: border-box;
                }
                .modal-content textarea { height: 100px; resize: none; }
                .modal-buttons { margin-top: 20px; text-align: right; }
                .btn {
                    padding: 8px 16px;
                    margin-left: 10px;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    color: #e8e8e8;
                }
                .btn-primary { background-color: #4d5156; width: 70px; margin-bottom: 3px; }
                .btn-secondary { background-color: #333; width: 70px; }
                .btn-danger { background-color: #dc3545; width: 70px; }
                .btn-add { background-color: #4d5156; padding: 10px 20px; margin-bottom: 10px; }
            </style>
            <script>
                function openModal(modalId) {
                    document.getElementById(modalId).style.display = 'flex';
                }
                function closeModal(modalId) {
                    document.getElementById(modalId).style.display = 'none';
                }
                function populateEditModal(id, fullName, birthDate, gender, medicalNotes) {
                    document.getElementById('editPatientId').value = id;
                    document.getElementById('editFullName').value = fullName;
                    document.getElementById('editBirthDate').value = birthDate;
                    document.getElementById('editGender').value = gender;
                    document.getElementById('editMedicalNotes').value = medicalNotes || '';
                    openModal('editPatientModal');
                }
            </script>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div class="logo">
                        <p>Doctor Dashboard</p>
                    </div>
                    <div class="links">
                        <a href="docs.html">Docs</a>
                        <a href="?logout">Logout</a>
                    </div>
                </div>
                <p>Welcome, <?php echo $display_name; ?>!</p>
                <button class="btn btn-add" onclick="openModal('addPatientModal')">Add Patient</button>
                <table>
                    <tr>
                        <th>Full Name</th>
                        <th>Birth Date</th>
                        <th>Gender</th>
                        <th>Medical Notes</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                    <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($patient['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($patient['birth_date']); ?></td>
                            <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                            <td><?php echo htmlspecialchars($patient['medical_notes'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($patient['created_at']); ?></td>
                            <td>
                                <button class="btn btn-primary" onclick="populateEditModal('<?php echo $patient['id']; ?>', '<?php echo htmlspecialchars($patient['full_name'], ENT_QUOTES); ?>', '<?php echo $patient['birth_date']; ?>', '<?php echo $patient['gender']; ?>', '<?php echo htmlspecialchars($patient['medical_notes'] ?? '', ENT_QUOTES); ?>')">Edit</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="patient_id" value="<?php echo $patient['id']; ?>">
                                    <input type="hidden" name="doctor_id" value="<?php echo $doctor['id']; ?>">
                                    <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this patient?');">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <!-- Add Patient Modal -->
            <div id="addPatientModal" class="modal">
                <div class="modal-content">
                    <h2>Add Patient</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="doctor_id" value="<?php echo $doctor['id']; ?>">
                        <label for="fullName">Full Name</label>
                        <input type="text" id="fullName" name="full_name" required>
                        <label for="birthDate">Birth Date</label>
                        <input type="date" id="birthDate" name="birth_date" required>
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender" required>
                            <option value="M">Male</option>
                            <option value="F">Female</option>
                            <option value="Other">Other</option>
                        </select>
                        <label for="medicalNotes">Medical Notes</label>
                        <textarea id="medicalNotes" name="medical_notes"></textarea>
                        <div class="modal-buttons">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('addPatientModal')">Close</button>
                            <button type="submit" class="btn btn-primary">Add Patient</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Patient Modal -->
            <div id="editPatientModal" class="modal">
                <div class="modal-content">
                    <h2>Edit Patient</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="patient_id" id="editPatientId">
                        <input type="hidden" name="doctor_id" value="<?php echo $doctor['id']; ?>">
                        <label for="editFullName">Full Name</label>
                        <input type="text" id="editFullName" name="full_name" required>
                        <label for="editBirthDate">Birth Date</label>
                        <input type="date" id="editBirthDate" name="birth_date" required>
                        <label for="editGender">Gender</label>
                        <select id="editGender" name="gender" required>
                            <option value="M">Male</option>
                            <option value="F">Female</option>
                            <option value="Other">Other</option>
                        </select>
                        <label for="editMedicalNotes">Medical Notes</label>
                        <textarea id="editMedicalNotes" name="medical_notes"></textarea>
                        <div class="modal-buttons">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('editPatientModal')">Close</button>
                            <button type="submit" class="btn btn-primary">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </body>
        </html>
        <?php
    } else {
        http_response_code(403);
        echo 'Access Denied: Unauthorized user. <a href="?logout">Logout</a>';
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Database error: ' . htmlspecialchars($e->getMessage());
}
?>
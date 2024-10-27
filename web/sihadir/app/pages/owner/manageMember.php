<?php
session_start();
require_once '../../../app/auth/auth.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: ../../../login.php');
    exit;
}

// Check if the user role is employee
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'owner') {
    // Unset session variables and destroy session
    session_unset();
    session_destroy();
    
    // Set headers to prevent caching
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    
    header('Location: ../../../login.php');
    exit;
}

// Fetch divisions from database
$stmt = $pdo->prepare("SELECT id, nama_divisi FROM divisi");
$stmt->execute();
$divisi_names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch shifts from database
$stmt = $pdo->prepare("SELECT id, nama_shift FROM shift");
$stmt->execute();
$shifts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get users with their division names (excluding owner role)
$stmt = $pdo->prepare("
    SELECT u.*, p.divisi_id, d.nama_divisi, s.id as shift_id, s.nama_shift
    FROM users u 
    LEFT JOIN pegawai p ON u.id = p.user_id 
    LEFT JOIN divisi d ON p.divisi_id = d.id
    LEFT JOIN jadwal_shift js ON p.id = js.pegawai_id
    LEFT JOIN shift s ON js.shift_id = s.id
    WHERE u.role != 'owner'
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Search functionality
if(isset($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $stmt = $pdo->prepare("
        SELECT u.*, p.divisi_id, d.nama_divisi, s.id as shift_id, s.nama_shift
        FROM users u 
        LEFT JOIN pegawai p ON u.id = p.user_id 
        LEFT JOIN divisi d ON p.divisi_id = d.id
        LEFT JOIN jadwal_shift js ON p.id = js.pegawai_id
        LEFT JOIN shift s ON js.shift_id = s.id
        WHERE u.role != 'owner' AND (
            u.nama_lengkap LIKE :search 
            OR u.email LIKE :search 
            OR u.username LIKE :search
            OR u.no_telp LIKE :search
        )
    ");
    $stmt->execute(['search' => $search]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Add user with proper transaction handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        $pdo->beginTransaction();

        // 1. Insert into users table
        $stmt = $pdo->prepare("
            INSERT INTO users (nama_lengkap, email, username, password, no_telp, role) 
            VALUES (:nama_lengkap, :email, :username, :password, :no_telp, 'karyawan')
        ");
        
        $stmt->execute([
            'nama_lengkap' => $_POST['nama_lengkap'],
            'email' => $_POST['email'],
            'username' => $_POST['username'],
            'password' => password_hash($_POST['password'], PASSWORD_DEFAULT),
            'no_telp' => $_POST['no_telp']
        ]);
        
        $userId = $pdo->lastInsertId();

        // 2. Insert into pegawai table
        $stmt = $pdo->prepare("
            INSERT INTO pegawai (user_id, divisi_id, status_aktif) 
            VALUES (:user_id, :divisi_id, 'aktif')
        ");
        
        $stmt->execute([
            'user_id' => $userId,
            'divisi_id' => $_POST['divisi_id']
        ]);

        $pegawaiId = $pdo->lastInsertId();

        // 3. Insert into jadwal_shift table
        $stmt = $pdo->prepare("
            INSERT INTO jadwal_shift (pegawai_id, shift_id, tanggal, status) 
            VALUES (:pegawai_id, :shift_id, CURRENT_DATE, 'aktif')
        ");
        
        $stmt->execute([
            'pegawai_id' => $pegawaiId,
            'shift_id' => $_POST['shift_id']
        ]);

        $pdo->commit();
        
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Member berhasil ditambahkan!'
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Gagal menambahkan member: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Add division
if(isset($_POST['add_division'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO divisi (nama_divisi) VALUES (:nama_divisi)");
        $stmt->execute(['nama_divisi' => $_POST['nama_divisi']]);
        
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Divisi berhasil ditambahkan!'
        ];
    } catch(PDOException $e) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Gagal menambahkan divisi: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Delete division
if(isset($_POST['delete_division'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM divisi WHERE id = :id");
        $stmt->execute(['id' => $_POST['division_id']]);
        
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Divisi berhasil dihapus!'
        ];
    } catch(PDOException $e) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Gagal menghapus divisi: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Update user with password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    try {
        $pdo->beginTransaction();

        // Base update query
        $updateQuery = "UPDATE users SET 
            nama_lengkap = :nama_lengkap,
            email = :email,
            username = :username,
            no_telp = :no_telp";
        
        // Add password to update if provided
        $params = [
            'user_id' => $_POST['user_id'],
            'nama_lengkap' => $_POST['nama_lengkap'],
            'email' => $_POST['email'],
            'username' => $_POST['username'],
            'no_telp' => $_POST['no_telp']
        ];

        if (!empty($_POST['password'])) {
            $updateQuery .= ", password = :password";
            $params['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }

        $updateQuery .= " WHERE id = :user_id";
        
        $stmt = $pdo->prepare($updateQuery);
        $stmt->execute($params);

        // Update division
        $stmt = $pdo->prepare("
            UPDATE pegawai 
            SET divisi_id = :divisi_id
            WHERE user_id = :user_id
        ");
        $stmt->execute([
            'divisi_id' => $_POST['divisi_id'],
            'user_id' => $_POST['user_id']
        ]);

        $pdo->commit();
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Member berhasil diupdate!'
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Gagal mengupdate member: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Delete user
if(isset($_POST['delete_user'])) {
    try {
        $pdo->beginTransaction();
        
        // Delete from jadwal_shift
        $stmt = $pdo->prepare("
            DELETE js FROM jadwal_shift js 
            INNER JOIN pegawai p ON js.pegawai_id = p.id 
            WHERE p.user_id = :user_id
        ");
        $stmt->execute(['user_id' => $_POST['user_id']]);
        
        // Delete from log_akses
        $stmt = $pdo->prepare("DELETE FROM log_akses WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $_POST['user_id']]);
        
        // Delete from pegawai
        $stmt = $pdo->prepare("DELETE FROM pegawai WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $_POST['user_id']]);
        
        // Delete from users
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :user_id");
        $stmt->execute(['user_id' => $_POST['user_id']]);
        
        $pdo->commit();
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'User berhasil dihapus!'
        ];
    } catch(PDOException $e) {
        $pdo->rollBack();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Gagal menghapus user: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Perbaiki fungsi remove device
if(isset($_POST['remove_device'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM log_akses WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $_POST['user_id']]);
        
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Device berhasil dihapus!'
        ];
    } catch(PDOException $e) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Gagal menghapus device: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Rest of your HTML code remains the same
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="" />
        <meta name="author" content="" />
        <title>Si Hadir - Dashboard</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
        <!-- Script untuk Bootstrap JS (jika perlu) -->
        <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
        <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
        <!-- Favicon-->
        <link rel="icon" type="image/x-icon" href="../../../assets/icon/favicon.ico" />
        <!-- Core theme CSS (includes Bootstrap)-->
        <link href="../../../assets/css/styles.css" rel="stylesheet" />
        <!-- Link Google Fonts untuk Poppins -->
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
        
        <style>
            /* Mengatur font Poppins hanya untuk <strong> di dalam sidebar-heading */
            #sidebar-wrapper .sidebar-heading strong {
                font-family: 'Poppins', sans-serif; /* Menggunakan font Poppins hanya untuk Si Hadir */
                font-weight: 900; /* Menebalkan tulisan */
                font-size: 28px;  /* Membesarkan ukuran font */
            }
            
            /* Menghilangkan tombol toggle navbar dan memastikan navbar selalu terlihat */
            .navbar-toggler {
                display: none;
            }
            
            #navbarSupportedContent {
                display: flex !important;
            }
            
            .status-card {
            transition: transform 0.3s, box-shadow 0.3s;
            }
            .status-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
            }

            .sidebar-icon {
            width: 24px;
            height: 24px;
            margin-right: 10px;
            vertical-align: middle;
            }

            /* Menyesuaikan tampilan navbar untuk layar kecil */
            @media (max-width: 991.98px) {
                .navbar-nav {
                    flex-direction: row;
                }
                
                .navbar-nav .nav-item {
                    padding-right: 10px;
                }
                
                .navbar-nav .dropdown-menu {
                    position: absolute;
                }
            }
        </style>
        
    </head>
    <body class="bg-blue-50">
        <div class="d-flex" id="wrapper">
            <!-- Sidebar-->
            <div class="border-end-0 bg-white" id="sidebar-wrapper">
              <div class="sidebar-heading border-bottom-0"><strong>Si Hadir</strong></div>
                <div class="list-group list-group-flush">
                <a class="list-group-item list-group-item-action list-group-item-light p-3 border-bottom-0" href="dashboard.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" class="sidebar-icon" fill="#6c757d">
                                <path d="M520-600v-240h320v240H520ZM120-440v-400h320v400H120Zm400 320v-400h320v400H520Zm-400 0v-240h320v240H120Zm80-400h160v-240H200v240Zm400 320h160v-240H600v240Zm0-480h160v-80H600v80ZM200-200h160v-80H200v80Zm160-320Zm240-160Zm0 240ZM360-280Z"/>
                            </svg>
                            Dashboard
                        </a>    
                        <a class="list-group-item list-group-item-action list-group-item-light p-3 border-bottom-0" href="attendanceMonitor.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" class="sidebar-icon" fill="#6c757d">
                                <path d="M160-80q-33 0-56.5-23.5T80-160v-440q0-33 23.5-56.5T160-680h200v-120q0-33 23.5-56.5T440-880h80q33 0 56.5 23.5T600-800v120h200q33 0 56.5 23.5T880-600v440q0 33-23.5 56.5T800-80H160Zm0-80h640v-440H600q0 33-23.5 56.5T520-520h-80q-33 0-56.5-23.5T360-600H160v440Zm80-80h240v-18q0-17-9.5-31.5T444-312q-20-9-40.5-13.5T360-330q-23 0-43.5 4.5T276-312q-17 8-26.5 22.5T240-258v18Zm320-60h160v-60H560v60Zm-200-60q25 0 42.5-17.5T420-420q0-25-17.5-42.5T360-480q-25 0-42.5 17.5T300-420q0 25 17.5 42.5T360-360Zm200-60h160v-60H560v60ZM440-600h80v-200h-80v200Zm40 220Z"/>
                            </svg>
                            Monitor Absensi
                        </a>
                        <a class="list-group-item list-group-item-action list-group-item-light p-3 border-bottom-0" href="schedule.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="sidebar-icon" fill="#6c757d">
                                <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z"/>
                            </svg>
                            Jadwal
                        </a>
                        <a class="list-group-item list-group-item-action list-group-item-light p-3 border-bottom-0" href="manageMember.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="sidebar-icon" fill="#6c757d" width="24" height="24">
                                <path d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V20h14v-3.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 2.02 1.97 3.45V20h6v-3.5c0-2.33-4.67-3.5-7-3.5z"/>
                            </svg>
                            Manajemen Staff
                        </a>
                        <a class="list-group-item list-group-item-action list-group-item-light p-3 border-bottom-0" href="permit.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" class="sidebar-icon" fill="#6c757d">
                                <path d="M160-200v-440 440-15 15Zm0 80q-33 0-56.5-23.5T80-200v-440q0-33 23.5-56.5T160-720h160v-80q0-33 23.5-56.5T400-880h160q33 0 56.5 23.5T640-800v80h160q33 0 56.5 23.5T880-640v171q-18-13-38-22.5T800-508v-132H160v440h283q3 21 9 41t15 39H160Zm240-600h160v-80H400v80ZM720-40q-83 0-141.5-58.5T520-240q0-83 58.5-141.5T720-440q83 0 141.5 58.5T920-240q0 83-58.5 141.5T720-40Zm20-208v-112h-40v128l86 86 28-28-74-74Z"/>
                            </svg>
                            Cuti & Perizinan
                        </a>
                        <a class="list-group-item list-group-item-action list-group-item-light p-3 border-bottom-0" href="report.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="sidebar-icon" width="24" height="24" stroke="#6c757d" fill="none" stroke-width="2">
                                <path d="M6 2C5.44772 2 5 2.44772 5 3V21C5 21.5523 5.44772 22 6 22H18C18.5523 22 19 21.5523 19 21V7L14 2H6Z" />
                                <path d="M13 2V7H19" />
                            </svg>
                            Laporan
                        </a>
                        <a class="list-group-item list-group-item-action list-group-item-light p-3 border-bottom-0" href="logout.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" class="sidebar-icon" fill="#6c757d">
                                <path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/>
                            </svg>
                            Log out
                        </a>
                </div>
            </div>

            <div class="toast" id="successToast" style="position: absolute; top: 20px; right: 20px;" data-autohide="true" data-delay="3000">
                <div class="toast-header">
                    <strong class="mr-auto">Sukses</strong>
                    <button type="button" class="ml-2 mb-1 close" data-dismiss="toast" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="toast-body">
                    Member berhasil ditambahkan!
                </div>
            </div>

            <!-- Page content wrapper-->
            <div id="page-content-wrapper">
                <!-- Top navigation-->
                <nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom">
                    <div class="container-fluid">
                        <button class="btn btn-primary" id="sidebarToggle">☰</button>
                        <div id="navbarSupportedContent">
                        </div>
                    </div>
                </nav>
                <!-- Toast notification -->
    <?php if(isset($_SESSION['toast'])): ?>
    <div id="toast" class="fixed top-4 right-4 px-4 py-2 rounded shadow-lg z-50 <?= $_SESSION['toast']['type'] === 'success' ? 'bg-green-500' : 'bg-red-500' ?> text-white">
        <?= $_SESSION['toast']['message'] ?>
    </div>
    <script>
        setTimeout(() => {
            document.getElementById('toast').style.display = 'none';
        }, 3000);
    </script>
    <?php unset($_SESSION['toast']); endif; ?>

    <!-- Page content -->
    <div class="container-fluid p-4">
        <h1 class="text-3xl font-semibold mb-4">Manajemen Staff</h1>
        <!-- Modified button section in your HTML -->
<div class="flex items-center justify-between mb-4">
    <div class="flex space-x-2">
        <button class="bg-blue-500 text-white px-4 py-2 rounded" data-bs-toggle="modal" data-bs-target="#addMemberModal">
            Tambah Member
        </button>
        <button class="bg-green-500 text-white px-4 py-2 rounded" data-bs-toggle="modal" data-bs-target="#addDivisionModal">
            Atur Divisi
        </button>
    </div>
            <form action="" method="GET" class="flex">
                <input type="text" name="search" class="border border-gray-300 rounded px-2 py-1" placeholder="Cari nama/email/username/telepon" value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                <button type="submit" class="bg-blue-500 text-white px-4 py-1 rounded ml-2">Cari</button>
            </form>
        </div>

        <!-- Table content -->
        <div class="bg-white shadow rounded-lg p-4 mb-4">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Staff</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Divisi</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">No Telepon</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200 text-center">
                    <?php foreach ($users as $user) : ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($user['nama_lengkap']) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($user['username']) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($user['email']) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($user['nama_divisi'] ?? '-') ?></td>
                        <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($user['no_telp']) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
    <div class="flex space-x-2 justify-center">
        <button onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)" 
            class="px-4 py-2 bg-green-100 text-green-700 rounded-full text-sm">
            Edit
        </button>
        <button onclick="showDeleteConfirm(<?= $user['id'] ?>, '<?= htmlspecialchars($user['nama_lengkap']) ?>')" 
            class="px-4 py-2 bg-red-100 text-red-700 rounded-full text-sm">
            Hapus
        </button>
        <button onclick="showRemoveDeviceConfirm(<?= $user['id'] ?>, '<?= htmlspecialchars($user['nama_lengkap']) ?>')" 
            class="px-4 py-2 bg-blue-100 text-blue-700 rounded-full text-sm">
            Remove Device
        </button>
    </div>
</td>

                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Member Modal -->
    <div class="modal fade" id="addMemberModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Member</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addMemberForm" action="" method="POST">
                        <input type="hidden" name="add_user" value="1">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Nama Lengkap</label>
                                <input type="text" class="form-control" name="nama_lengkap" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" name="username" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password</label>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">No Telepon</label>
                                <input type="text" class="form-control" name="no_telp" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Divisi</label>
                                <select class="form-select" name="divisi_id" required>
                                    <option value="">Pilih Divisi</option>
                                    <?php foreach ($divisi_names as $id => $nama): ?>
                                        <option value="<?= $id ?>"><?= htmlspecialchars($nama) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                            <label class="form-label">Shift</label>
                            <select class="form-select" name="shift_id" required>
                                <option value="">Pilih Shift</option>
                                <?php foreach ($shifts as $id => $nama): ?>
                                    <option value="<?= $id ?>"><?= htmlspecialchars($nama) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                            <button type="submit" class="btn btn-primary">Tambah</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Member Modal -->
    <div class="modal fade" id="editMemberModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Member</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editMemberForm" action="" method="POST">
                        <input type="hidden" name="update_user" value="1">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Nama Lengkap</label>
                                <input type="text" class="form-control" name="nama_lengkap" id="edit_nama_lengkap" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" id="edit_email" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" name="username" id="edit_username" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password Baru</label>
                                <input type="password" class="form-control" name="password">
                            </div>
                            
                        </div>
                        <div class="row mb-3">
                        <div class="col-md-6">
                                <label class="form-label">No Telepon</label>
                                <input type="text" class="form-control" name="no_telp" id="edit_no_telp" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Divisi</label>
                                <select class="form-select" name="divisi_id" id="edit_divisi_id" required>
                                    <option value="">Pilih Divisi</option>
                                    <?php foreach ($divisi_names as $id => $nama): ?>
                                        <option value="<?= $id ?>"><?= htmlspecialchars($nama) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus user <span id="delete_user_name" class="font-semibold"></span>?</p>
                </div>
                <div class="modal-footer">
                    <form id="deleteForm" action="" method="POST">
                        <input type="hidden" name="delete_user" value="1">
                        <input type="hidden" name="user_id" id="delete_user_id">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">Hapus</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
   <!-- Modify the Add Division Modal -->
<div class="modal fade" id="addDivisionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Manajemen Divisi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Add Division Form -->
                <form id="addDivisionForm" action="" method="POST" class="mb-4">
                    <input type="hidden" name="add_division" value="1">
                    <div class="mb-3">
                        <label class="form-label">Tambah Divisi Baru</label>
                        <input type="text" class="form-control" name="nama_divisi" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Tambah Divisi</button>
                </form>

                <!-- Division List -->
                <div class="mt-4">
                    <h6 class="mb-3">Daftar Divisi</h6>
                    <?php foreach ($divisi_names as $id => $nama): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span><?= htmlspecialchars($nama) ?></span>
                        <form action="" method="POST" class="d-inline">
                            <input type="hidden" name="delete_division" value="1">
                            <input type="hidden" name="division_id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-danger btn-sm" 
                                    onclick="return confirm('Yakin ingin menghapus divisi ini?')">
                                Hapus
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Remove Device Confirmation Modal -->
<div class="modal fade" id="removeDeviceConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Konfirmasi Hapus Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Apakah Anda yakin ingin menghapus semua device untuk user <span id="remove_device_user_name" class="font-semibold"></span>?</p>
            </div>
            <div class="modal-footer">
                <form id="removeDeviceForm" action="" method="POST">
                    <input type="hidden" name="remove_device" value="1">
                    <input type="hidden" name="user_id" id="remove_device_user_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning">Hapus Device</button>
                </form>
            </div>
        </div>
    </div>
</div>


    <!-- Scripts -->
    <script>
        // Function to handle edit user
        function editUser(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_nama_lengkap').value = user.nama_lengkap;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_no_telp').value = user.no_telp;
            document.getElementById('edit_divisi_id').value = user.divisi_id || '';
            
            // Show the modal
            new bootstrap.Modal(document.getElementById('editMemberModal')).show();
        }

        function showDeleteConfirm(userId, userName) {
        document.getElementById('delete_user_id').value = userId;
        document.getElementById('delete_user_name').textContent = userName;
        new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
    }

    function showRemoveDeviceConfirm(userId, userName) {
        document.getElementById('remove_device_user_id').value = userId;
        document.getElementById('remove_device_user_name').textContent = userName;
        new bootstrap.Modal(document.getElementById('removeDeviceConfirmModal')).show();
    }

        // Function to handle delete user
        function deleteUser(userId, userName) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_user_name').textContent = userName;
            
            // Show the modal
            new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
        }

        function removeDevice(userId, userName) {
    if(confirm(`Apakah Anda yakin ingin menghapus device untuk user ${userName}?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'remove_device';
        input.value = '1';
        
        const userIdInput = document.createElement('input');
        userIdInput.type = 'hidden';
        userIdInput.name = 'user_id';
        userIdInput.value = userId;
        
        form.appendChild(input);
        form.appendChild(userIdInput);
        document.body.appendChild(form);
        form.submit();
    }
}

        // Auto-hide toast after 3 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const toast = document.getElementById('toast');
            if (toast) {
                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => {
                        toast.remove();
                    }, 500);
                }, 3000);
            }
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
    
    <!-- Bootstrap and other scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../../assets/js/scripts.js"></script>
</body>
</html>
        
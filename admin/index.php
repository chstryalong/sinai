<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

include("../config/db.php");

/* ADD / UPDATE */
if (isset($_POST['save'])) {
    $id = $_POST['id'];
    $name = $_POST['name'];
    $dept = $_POST['department'];
    $status = $_POST['status'];
    $resume = $_POST['resume_date'];

    if ($id == "") {
        $stmt = $conn->prepare(
            "INSERT INTO doctors (name, department, status, resume_date)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("ssss", $name, $dept, $status, $resume);
    } else {
        $stmt = $conn->prepare(
            "UPDATE doctors SET name=?, department=?, status=?, resume_date=? WHERE id=?"
        );
        $stmt->bind_param("ssssi", $name, $dept, $status, $resume, $id);
    }
    $stmt->execute();
}

/* DELETE */
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM doctors WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
}

/* DELETE ALL */
if (isset($_POST['delete_all'])) {
    $conn->query("DELETE FROM doctors");
    header("Location: index.php");
    exit;
}

/* DELETE SELECTED */
if (isset($_POST['delete_selected']) && isset($_POST['selected_ids'])) {
    $ids = $_POST['selected_ids'];
    foreach ($ids as $id) {
        $id = intval($id);
        $stmt = $conn->prepare("DELETE FROM doctors WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
}

/* EDIT */
$edit = null;
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM doctors WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
}

/* FETCH ALL */
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'ASC';

$query = "SELECT * FROM doctors WHERE 1=1";

if ($search) {
    $search_term = "%$search%";
    $query .= " AND (name LIKE ? OR department LIKE ?)";
}

// Validate sort column
$valid_sorts = ['name', 'department', 'status'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'name';
}

// Validate order
if ($order !== 'ASC' && $order !== 'DESC') {
    $order = 'ASC';
}

$query .= " ORDER BY $sort $order";

if ($search) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sinai MDI Hospital - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #0052CC;
            --secondary-blue: #1e88e5;
            --accent-yellow: #ffc107;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            border-top: 4px solid var(--primary-blue);
        }

        .form-label {
            color: var(--primary-blue);
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 0.2rem rgba(0, 82, 204, 0.25);
        }

        .btn-save {
            background: linear-gradient(135deg, var(--accent-yellow) 0%, #ffb300 100%);
            color: var(--primary-blue);
            border: none;
            padding: 12px 30px;
            font-weight: 700;
            border-radius: 8px;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 15px;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 193, 7, 0.3);
            color: var(--primary-blue);
        }

        .table-section {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .table {
            margin-bottom: 0;
        }

        .table thead {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
        }

        .table thead th {
            color: white;
            font-weight: 600;
            border: none;
            padding: 15px;
        }

        .table tbody td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background-color: #f5f5f5;
        }

        .badge-available {
            background-color: #28a745;
        }

        .badge-unavailable {
            background-color: #dc3545;
        }

        .badge-leave {
            background-color: #ffc107;
            color: #333;
        }

        .btn-action {
            padding: 6px 12px;
            margin: 2px;
            font-size: 12px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .btn-edit {
            background-color: #0052CC;
            color: white;
        }

        .btn-edit:hover {
            background-color: #0041a3;
            color: white;
        }

        .btn-delete {
            background-color: #dc3545;
            color: white;
        }

        .btn-delete:hover {
            background-color: #c82333;
            color: white;
        }

        .btn-logout {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .btn-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
            color: white;
        }

        .section-title {
            color: var(--primary-blue);
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            font-size: 28px;
        }

        .content-wrapper {
            padding: 20px 0;
        }

        .form-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-left: 5px solid #ffc107;
        }

        .table-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-left: 5px solid #0052CC;
        }

        .section-title {
            background: linear-gradient(135deg, #0052CC 0%, #1e88e5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        @media (max-width: 1200px) {
            .col-lg-10 {
                max-width: 95%;
            }
        }

        @media (max-width: 768px) {
            .section-title {
                font-size: 20px;
            }

            .form-section, .table-section {
                padding: 20px;
            }

            .navbar-brand {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <span class="navbar-brand">
                <i class="bi bi-hospital"></i>
                Sinai MDI Hospital
            </span>
            <div class="ms-auto">
                <span class="text-white me-3">
                    <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['admin']) ?>
                </span>
                <a href="logout.php" class="btn-logout">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-lg-10 mx-auto">
                <div>
                    <h1 class="section-title">
                        <i class="bi bi-pencil-square"></i>
                        Doctor Schedule Management
                    </h1>
                </div>

                <!-- Add/Edit Form -->
                <div class="form-section">
            <h4 class="section-title" style="font-size: 18px; margin-bottom: 25px;">
                <i class="bi bi-plus-circle"></i>
                <?= $edit ? 'Edit Doctor Information' : 'Add New Doctor' ?>
            </h4>

            <form method="POST">
                <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="name" class="form-label">Doctor Name</label>
                        <input type="text" class="form-control" id="name" name="name" value="<?= $edit['name'] ?? '' ?>" required>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="department" class="form-label">Department</label>
                        <select class="form-select" id="department" name="department" required>
                            <option value="">Select Department</option>
                            <?php
                            $departments = ['OPD','ER','Pediatrics','Cardiology','Radiology','Laboratory'];
                            foreach ($departments as $d) {
                                $selected = ($edit && $edit['department'] == $d) ? "selected" : "";
                                echo "<option value='$d' $selected>$d</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <?php
                            $statuses = ['Available','Not Available','On Leave'];
                            foreach ($statuses as $s) {
                                $selected = ($edit && $edit['status'] == $s) ? "selected" : "";
                                echo "<option $selected>$s</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="resume_date" class="form-label">Resume Date</label>
                        <input type="date" class="form-control" id="resume_date" name="resume_date" value="<?= $edit['resume_date'] ?? '' ?>">
                    </div>
                </div>

                <button type="submit" name="save" class="btn-save">
                    <i class="bi bi-check-circle"></i> <?= $edit ? 'Update Doctor' : 'Add Doctor' ?>
                </button>
            </form>
        </div>

        <!-- Doctor List Table -->
        <div class="table-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h4 class="section-title" style="font-size: 18px; margin-bottom: 0;">
                    <i class="bi bi-list-check"></i>
                    Doctor List
                </h4>
                
                <form method="GET" style="display: flex; gap: 10px; flex: 1; max-width: 400px;">
                    <div style="flex: 1;">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by name or department..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <button type="submit" class="btn btn-sm" style="background: var(--primary-blue); color: white; border: none;">
                        <i class="bi bi-search"></i> Search
                    </button>
                    <?php if ($search): ?>
                        <a href="?" class="btn btn-sm" style="background: #6c757d; color: white; border: none;">
                            <i class="bi bi-x"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>

                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn btn-sm btn-warning" id="delete-selected-btn" onclick="deleteSelected()" style="display: none;">
                        <i class="bi bi-trash"></i> Delete Selected
                    </button>
                    <button type="button" class="btn btn-sm btn-danger" onclick="showDeleteAllModal()">
                        <i class="bi bi-trash"></i> Delete All
                    </button>
                </div>
            </div>

            <form method="POST" id="bulk-delete-form" style="display: none;">
                <input type="hidden" name="delete_selected" value="1">
                <input type="hidden" id="selected_ids_input" name="selected_ids[]" value="">
            </form>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 30px;">
                                <input type="checkbox" id="select-all" onclick="toggleSelectAll()">
                            </th>
                            <th>
                                <a href="?sort=name&order=<?= $sort === 'name' && $order === 'ASC' ? 'DESC' : 'ASC' ?>&search=<?= urlencode($search) ?>" style="text-decoration: none; color: white;">
                                    <i class="bi bi-person"></i> Name
                                    <?php if ($sort === 'name'): ?>
                                        <i class="bi <?= $order === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down' ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?sort=department&order=<?= $sort === 'department' && $order === 'ASC' ? 'DESC' : 'ASC' ?>&search=<?= urlencode($search) ?>" style="text-decoration: none; color: white;">
                                    <i class="bi bi-building"></i> Department
                                    <?php if ($sort === 'department'): ?>
                                        <i class="bi <?= $order === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down' ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?sort=status&order=<?= $sort === 'status' && $order === 'ASC' ? 'DESC' : 'ASC' ?>&search=<?= urlencode($search) ?>" style="text-decoration: none; color: white;">
                                    <i class="bi bi-toggle-on"></i> Status
                                    <?php if ($sort === 'status'): ?>
                                        <i class="bi <?= $order === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down' ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th><i class="bi bi-calendar"></i> Resume Date</th>
                            <th><i class="bi bi-gear"></i> Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="doctor-checkbox" value="<?= $row['id'] ?>">
                            </td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= htmlspecialchars($row['department']) ?></td>
                            <td>
                                <?php
                                if ($row['status'] == 'Available') {
                                    echo '<span class="badge badge-available">Available</span>';
                                } elseif ($row['status'] == 'Not Available') {
                                    echo '<span class="badge badge-unavailable">Not Available</span>';
                                } else {
                                    echo '<span class="badge badge-leave">On Leave</span>';
                                }
                                ?>
                            </td>
                            <td><?= $row['resume_date'] ?? '-' ?></td>
                            <td>
                                <a href="?edit=<?= $row['id'] ?>" class="btn-action btn-edit">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                                <a href="?delete=<?= $row['id'] ?>" class="btn-action btn-delete" onclick="return confirm('Are you sure you want to delete this doctor?')">
                                    <i class="bi bi-trash"></i> Delete
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    </div>

    <!-- Delete All Modal -->
    <div class="modal fade" id="deleteAllModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%); color: white;">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Delete All Doctors</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p><strong>⚠️ Warning:</strong> This will <strong>permanently delete ALL doctors</strong> from the system, including available doctors. This action <strong>CANNOT be undone!</strong></p>
                    <p>Type <strong>DELETE ALL</strong> to confirm:</p>
                    <input type="text" id="confirm-input" class="form-control" placeholder="Type DELETE ALL">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="delete_all" value="1" class="btn btn-danger" id="confirm-delete-btn" disabled onclick="return validateDeleteAll()">
                            Delete ALL Doctors
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Store scroll position before page reload
        let scrollPosition = 0;

        // Save scroll position when page is about to unload
        window.addEventListener('beforeunload', function() {
            sessionStorage.setItem('scrollPosition', window.scrollY);
        });

        // Restore scroll position when page loads
        window.addEventListener('load', function() {
            scrollPosition = sessionStorage.getItem('scrollPosition');
            if (scrollPosition) {
                window.scrollTo(0, parseInt(scrollPosition));
            }
        });

        // Auto-refresh every 30 seconds without jumping to top
        setInterval(function() {
            // Save current scroll position
            sessionStorage.setItem('scrollPosition', window.scrollY);
            
            // Reload page silently
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const newDoc = parser.parseFromString(html, 'text/html');
                    
                    // Update only the table tbody content
                    const oldTable = document.querySelector('table tbody');
                    const newTable = newDoc.querySelector('table tbody');
                    
                    if (oldTable && newTable && oldTable.innerHTML !== newTable.innerHTML) {
                        oldTable.innerHTML = newTable.innerHTML;
                        
                        // Re-attach event listeners to checkboxes
                        attachCheckboxListeners();
                    }
                })
                .catch(error => console.log('Auto-refresh check completed'));
        }, 30000); // 30 seconds

        function attachCheckboxListeners() {
            document.querySelectorAll('.doctor-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('.doctor-checkbox');
                    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                    document.getElementById('select-all').checked = allChecked;
                    updateDeleteButton();
                });
            });
        }

        function showDeleteAllModal() {
            const modal = new bootstrap.Modal(document.getElementById('deleteAllModal'));
            modal.show();
            document.getElementById('confirm-input').value = '';
            document.getElementById('confirm-delete-btn').disabled = true;
        }

        function deleteSelected() {
            const checkboxes = document.querySelectorAll('.doctor-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one doctor to delete');
                return;
            }

            if (!confirm(`Are you sure you want to delete ${checkboxes.length} doctor(s)? This action cannot be undone!`)) {
                return;
            }

            // Collect selected IDs
            const selectedIds = Array.from(checkboxes).map(cb => cb.value);
            
            // Create hidden inputs and submit form
            const form = document.getElementById('bulk-delete-form');
            const inputContainer = form.querySelector('input[type="hidden"]:last-of-type').parentElement;
            
            // Clear previous hidden inputs
            form.querySelectorAll('input[type="hidden"][name="selected_ids[]"]').forEach(el => el.remove());
            
            // Add new hidden inputs for each selected ID
            selectedIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_ids[]';
                input.value = id;
                form.appendChild(input);
            });
            
            form.submit();
        }

        document.getElementById('confirm-input').addEventListener('input', function() {
            document.getElementById('confirm-delete-btn').disabled = this.value !== 'DELETE ALL';
        });

        function validateDeleteAll() {
            if (document.getElementById('confirm-input').value === 'DELETE ALL') {
                return confirm('Are you absolutely sure? All doctors will be permanently deleted!');
            }
            return false;
        }

        function toggleSelectAll() {
            const checkboxes = document.querySelectorAll('.doctor-checkbox');
            const selectAll = document.getElementById('select-all');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            updateDeleteButton();
        }

        function updateDeleteButton() {
            const checkedCount = document.querySelectorAll('.doctor-checkbox:checked').length;
            const deleteBtn = document.getElementById('delete-selected-btn');
            if (checkedCount > 0) {
                deleteBtn.style.display = 'block';
                deleteBtn.textContent = `Delete Selected (${checkedCount})`;
            } else {
                deleteBtn.style.display = 'none';
            }
        }

        // Update select-all checkbox state when individual checkboxes change
        attachCheckboxListeners();

        // Initial check
        updateDeleteButton();
    </script>
</body>
</html>

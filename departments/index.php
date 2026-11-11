<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_role(['super_admin', 'admin']);
verify_csrf();

$db = db();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action', 20);
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = post_string('name', 100);
            $code = strtoupper(post_string('code', 20));
            $description = post_string('description', 2000);
            if ($name === '' || !preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
                throw new RuntimeException('Enter a department name and a code (2–20 letters/numbers).');
            }
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE departments SET name=?, code=?, description=? WHERE id=?');
                $stmt->execute([$name, $code, $description, $id]);
                audit((int)current_user()['id'], 'DEPARTMENT_UPDATED', 'departments', (string)$id, $name);
                flash('success', 'Department updated.');
            } else {
                $stmt = $db->prepare('INSERT INTO departments (name, code, description) VALUES (?,?,?)');
                $stmt->execute([$name, $code, $description]);
                $newId = (int)$db->lastInsertId();
                audit((int)current_user()['id'], 'DEPARTMENT_CREATED', 'departments', (string)$newId, $name);
                flash('success', 'Department created.');
            }
            redirect('departments/index.php');
        }
        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("UPDATE departments SET status=IF(status='active','inactive','active') WHERE id=?");
            $stmt->execute([$id]);
            audit((int)current_user()['id'], 'DEPARTMENT_STATUS_CHANGED', 'departments', (string)$id);
            flash('success', 'Department status updated.');
            redirect('departments/index.php');
        }
        throw new RuntimeException('Invalid department action.');
    } catch (Throwable $ex) {
        $error = str_contains(strtolower($ex->getMessage()), 'duplicate')
            ? 'Department name or code is already in use.'
            : $ex->getMessage();
    }
}

$rows = $db->query('SELECT d.*, (SELECT COUNT(*) FROM doctors x WHERE x.department_id=d.id) doctor_count FROM departments d ORDER BY d.name')->fetchAll();
$pageTitle = 'Departments';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/page.php';
page_heading('Clinic setup', 'Departments', 'Organize clinical services and assign doctors to departments.');
?>
<?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
<div class="row g-4">
    <div class="col-lg-4"><div class="card"><div class="card-header"><h2 class="section-title">Add department</h2></div><div class="card-body">
        <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save">
            <div class="mb-3"><label class="form-label">Department name</label><input class="form-control" name="name" maxlength="100" required></div>
            <div class="mb-3"><label class="form-label">Department code</label><input class="form-control" name="code" maxlength="20" required placeholder="e.g. NEURO"></div>
            <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"></textarea></div>
            <button class="btn btn-primary">Add department</button>
        </form>
    </div></div></div>
    <div class="col-lg-8"><div class="card"><div class="card-header"><h2 class="section-title">Department register</h2></div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Department</th><th>Code</th><th>Doctors</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr><td><strong><?=e($row['name'])?></strong><div class="small-muted"><?=e($row['description'])?></div></td><td><code><?=e($row['code'])?></code></td><td><?= (int)$row['doctor_count']?></td><td><?=status_badge($row['status'])?></td><td><form method="post" data-confirm="Change department availability?"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn btn-sm btn-outline-secondary"><?=$row['status']==='active'?'Deactivate':'Activate'?></button></form></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><?=empty_row(5)?><?php endif; ?>
        </tbody></table></div>
    </div></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

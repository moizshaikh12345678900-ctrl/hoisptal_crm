<?php
require_once __DIR__.'/../includes/functions.php';require_role(['super_admin','admin','staff']);$db=db();$doctors=$db->query("SELECT d.*,u.name,u.email,u.phone,u.status,dep.name department_name FROM doctors d JOIN users u ON u.id=d.user_id JOIN departments dep ON dep.id=d.department_id ORDER BY u.name")->fetchAll();$pageTitle='Doctors';require __DIR__.'/../includes/header.php';require __DIR__.'/../includes/page.php';page_heading('Clinical team','Doctors','Directory of clinicians and their specializations',current_user()['role']==='super_admin'?'<a class="btn btn-primary" href="'.e(url('users/index.php')).'"><i class="bi bi-plus-lg me-1"></i>Add doctor</a>':'');?>
<div class="row g-3"><?php foreach($doctors as $d):?><div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <div class="doctor-avatar"><i class="bi bi-person-badge"></i></div>
                    <div class="flex-grow-1">
                        <h2 class="h6 fw-bold mb-1"><?=e($d['name'])?></h2>
                        <div class="small text-secondary"><?=e($d['specialization'])?></div>
                        <div class="mt-2"><?=status_badge($d['status'])?></div>
                    </div>
                </div>
                <hr class="my-3">
                <div class="d-flex justify-content-between mb-2"><span
                        class="text-secondary">Department</span><strong><?=e($d['department_name'])?></strong></div>
                <div class="d-flex justify-content-between mb-2"><span
                        class="text-secondary">Qualification</span><strong><?=e($d['qualification'])?></strong></div>
                <div class="d-flex justify-content-between"><span class="text-secondary">Consultation fee</span><strong
                        class="text-success"><?=money($d['fee'])?></strong></div>
                <div class="small-muted mt-3"><?=e($d['email'])?></div>
            </div>
        </div>
    </div><?php endforeach;?></div><?php if(!$doctors):?><div class="card mt-3">
    <div class="empty-state"><i class="bi bi-person-plus"></i>No doctors have been added yet.</div>
</div><?php endif;?><?php require __DIR__.'/../includes/footer.php';?>
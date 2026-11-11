<?php
require_once __DIR__.'/../includes/functions.php';require_role(['patient']);$p=patient_for_user(current_user()['id']);if(!$p){flash('warning','Your patient profile is not linked. Contact the clinic.');redirect('patient/dashboard.php');}$s=db()->prepare("SELECT mr.*,du.name doctor_name FROM medical_records mr JOIN doctors d ON d.id=mr.doctor_id JOIN users du ON du.id=d.user_id WHERE mr.patient_id=? ORDER BY mr.created_at DESC");$s->execute([$p['id']]);$rows=$s->fetchAll();$pageTitle='My medical records';require __DIR__.'/../includes/header.php';require __DIR__.'/../includes/page.php';page_heading('Personal health','My medical records','Only records linked to your patient profile are shown.');?><?php foreach($rows as $r):?>
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2">
            <div>
                <h2 class="h6 fw-bold mb-1" data-no-i18n><?=e($r['diagnosis'])?></h2>
                <div class="small-muted"><span data-no-i18n>Dr. <?=e($r['doctor_name'])?></span> · <?=e(substr($r['created_at'],0,10))?></div>
            </div><?php if($r['follow_up_date']):?><span class="badge text-bg-light border">Follow-up:
                <?=e($r['follow_up_date'])?></span><?php endif;?>
        </div><?php if($r['symptoms']):?><p class="mt-3 mb-1"><strong>Symptoms:</strong> <span data-no-i18n><?=e($r['symptoms'])?></span></p>
        <?php endif;?><?php if($r['notes']):?><p class="mb-0"><strong>Notes:</strong> <span data-no-i18n><?=e($r['notes'])?></span></p>
        <?php endif;?>
    </div>
</div><?php endforeach;?><?php if(!$rows):?><div class="card">
    <div class="empty-state"><i class="bi bi-file-medical"></i>No medical records have been released to your portal.
    </div>
</div><?php endif;?><?php require __DIR__.'/../includes/footer.php';?>
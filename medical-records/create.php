<?php
require_once __DIR__.'/../includes/functions.php';require_role(['doctor','super_admin']);verify_csrf();$db=db();$user=current_user();$aid=(int)($_GET['appointment_id']??$_POST['appointment_id']??0);$s=$db->prepare("SELECT a.*,p.id patient_id,p.patient_number,COALESCE(NULLIF(p.full_name,''),pu.name,'Patient') patient_name,d.id doctor_id,du.name doctor_name FROM appointments a JOIN patients p ON p.id=a.patient_id LEFT JOIN users pu ON pu.id=p.user_id JOIN doctors d ON d.id=a.doctor_id JOIN users du ON du.id=d.user_id WHERE a.id=? LIMIT 1");$s->execute([$aid]);$apt=$s->fetch();if(!$apt){http_response_code(404);exit('Appointment not found.');}if($user['role']==='doctor' && (int)$apt['doctor_id']!==(int)(doctor_for_user($user['id'])['id']??0)){http_response_code(403);exit('You can only access your assigned appointments.');}$error='';if(in_array($apt['status'],['cancelled','no_show'],true)){http_response_code(403);exit('Consultation cannot be recorded for a cancelled or missed appointment.');}if($_SERVER['REQUEST_METHOD']==='POST'){$diagnosis=post_string('diagnosis',5000);$symptoms=post_string('symptoms',5000);$notes=post_string('notes',5000);$bp=post_string('bp',50);$pulse=post_string('pulse',50);$temp=post_string('temp',50);$weight=post_string('weight',50);$spo2=post_string('spo2',50);$follow=post_string('follow_up_date',10);$medicine=post_string('medicine_name',200);$dosage=post_string('dosage',100);$frequency=post_string('frequency',100);$duration=post_string('duration',100);$rxNotes=post_string('rx_notes',2000);if(!$diagnosis)$error='Diagnosis is required.';else{try{$db->beginTransaction();$lock=$db->prepare('SELECT id FROM appointments WHERE id=? FOR UPDATE');$lock->execute([$aid]);if(!$lock->fetch())throw new RuntimeException('Appointment no longer exists.');$duplicate=$db->prepare('SELECT id FROM medical_records WHERE appointment_id=? LIMIT 1');$duplicate->execute([$aid]);if($duplicate->fetch())throw new RuntimeException('A consultation record already exists for this appointment. Open the existing medical record instead.');$ins=$db->prepare('INSERT INTO medical_records(patient_id,doctor_id,appointment_id,diagnosis,symptoms,notes,bp,pulse,temp,weight,spo2,follow_up_date) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$ins->execute([$apt['patient_id'],$apt['doctor_id'],$aid,$diagnosis,$symptoms,$notes,$bp,$pulse,$temp,$weight,$spo2,$follow?:null]);$mrid=(int)$db->lastInsertId();if($medicine!==''||$dosage!==''||$frequency!==''||$duration!==''){if(!$medicine||!$dosage||!$frequency||!$duration)throw new RuntimeException('Complete medicine, dosage, frequency and duration or leave all medicine fields blank.');$rxnum='RX-'.date('Ymd').'-'.str_pad((string)$mrid,5,'0',STR_PAD_LEFT);$rx=$db->prepare('INSERT INTO prescriptions(prescription_number,medical_record_id,doctor_id,instructions) VALUES(?,?,?,?)');$rx->execute([$rxnum,$mrid,$apt['doctor_id'],$rxNotes]);$rxid=(int)$db->lastInsertId();$item=$db->prepare('INSERT INTO prescription_items(prescription_id,medicine_name,dosage,frequency,duration,notes) VALUES(?,?,?,?,?,?)');$item->execute([$rxid,$medicine,$dosage,$frequency,$duration,$rxNotes]);}$db->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$aid]);$invoiceResult=create_invoice_for_appointment($db,$aid);$db->commit();audit($user['id'],'MEDICAL_RECORD_CREATED','medical_records',(string)$mrid,'Consultation saved');if($invoiceResult['created']){audit($user['id'],'INVOICE_CREATED','invoices',(string)$invoiceResult['invoice']['id'],'Invoice: '.$invoiceResult['invoice']['invoice_number']);}flash('success','Consultation saved successfully.'.($invoiceResult['created']?' Invoice '.$invoiceResult['invoice']['invoice_number'].' generated.':' Existing invoice retained.'));redirect('medical-records/details.php?id='.$mrid);}catch(Throwable $ex){if($db->inTransaction())$db->rollBack();$error=$ex->getMessage();}}}
$pageTitle='Consultation';require __DIR__.'/../includes/header.php';require __DIR__.'/../includes/page.php';page_heading('Clinical care','Consultation notes','Record must be completed by the treating doctor.');?><?php if($error):?>
<div class="alert alert-danger"><?=e($error)?></div><?php endif;?><div class="card mb-4">
    <div class="card-body d-flex flex-wrap justify-content-between gap-3">
        <div>
            <div class="small-muted">PATIENT</div>
            <h2 class="h5 fw-bold mb-1"><?=e($apt['patient_name'])?></h2><span
                class="small-muted"><?=e($apt['patient_number'])?></span>
        </div>
        <div>
            <div class="small-muted">APPOINTMENT</div><strong><?=e($apt['appointment_date'])?> ·
                <?=e(substr($apt['start_at'],0,5))?></strong>
        </div>
        <div>
            <div class="small-muted">DOCTOR</div><strong><?=e($apt['doctor_name'])?></strong>
        </div>
    </div>
</div>
<form method="post" data-disable-submit><?=csrf_field()?><input type="hidden" name="appointment_id" value="<?=$aid?>">
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="section-title">Clinical assessment</h2>
        </div>
        <div class="card-body">
            <div class="mb-3"><label class="form-label">Diagnosis *</label><textarea class="form-control"
                    name="diagnosis" rows="3" required></textarea></div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Symptoms</label><textarea class="form-control"
                        name="symptoms" rows="2"></textarea></div>
                <div class="col-md-6"><label class="form-label">Treatment notes</label><textarea class="form-control"
                        name="notes" rows="2"></textarea></div>
                <?php foreach(['bp'=>'Blood pressure','pulse'=>'Pulse','temp'=>'Temperature','weight'=>'Weight','spo2'=>'SpO₂'] as $k=>$label):?>
                <div class="col-md-4"><label class="form-label"><?=e($label)?></label><input class="form-control"
                        name="<?=$k?>" maxlength="50"></div><?php endforeach;?><div class="col-md-4"><label
                        class="form-label">Follow-up date</label><input class="form-control" type="date"
                        name="follow_up_date" min="<?=date('Y-m-d')?>"></div>
            </div>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="section-title">Prescription (optional)</h2>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Medicine name</label><input class="form-control"
                        name="medicine_name"></div>
                <div class="col-md-6"><label class="form-label">Dosage</label><input class="form-control" name="dosage"
                        placeholder="e.g. 500 mg"></div>
                <div class="col-md-6"><label class="form-label">Frequency</label><input class="form-control"
                        name="frequency" placeholder="e.g. twice daily"></div>
                <div class="col-md-6"><label class="form-label">Duration</label><input class="form-control"
                        name="duration" placeholder="e.g. 5 days"></div>
                <div class="col-12"><label class="form-label">Prescription instructions</label><textarea
                        class="form-control" name="rx_notes" rows="2"></textarea></div>
            </div>
            <div class="form-text mt-2">If any medicine field is filled, all four medicine fields are required. This
                demo supports one medicine item per consultation.</div>
        </div>
    </div><button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save consultation</button> <a
        class="btn btn-light" href="<?=e(url('doctor/dashboard.php'))?>">Cancel</a>
</form><?php require __DIR__.'/../includes/footer.php';?>
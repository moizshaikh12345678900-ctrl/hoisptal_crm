<?php
require_once __DIR__.'/../includes/functions.php';require_role(['super_admin','admin','staff','doctor','patient']);$db=db();$user=current_user();$where='';$params=[];if($user['role']==='patient'){$p=patient_for_user($user['id']);$where=' WHERE a.patient_id=?';$params[]=(int)($p['id']??0);}elseif($user['role']==='doctor'){$d=doctor_for_user($user['id']);$where=' WHERE a.doctor_id=?';$params[]=(int)($d['id']??0);}$s=$db->prepare("SELECT a.*,COALESCE(NULLIF(p.full_name,''),pu.name,'Walk-in') patient_name,du.name doctor_name,dep.name department_name FROM appointments a JOIN patients p ON p.id=a.patient_id LEFT JOIN users pu ON pu.id=p.user_id JOIN doctors d ON d.id=a.doctor_id JOIN users du ON du.id=d.user_id JOIN departments dep ON dep.id=d.department_id$where ORDER BY a.appointment_date DESC,a.start_at DESC LIMIT 300");$s->execute($params);$rows=$s->fetchAll();$canBook=in_array($user['role'],['super_admin','admin','staff','patient'],true);$pageTitle='Appointments';require __DIR__.'/../includes/header.php';require __DIR__.'/../includes/page.php';page_heading('Scheduling','Appointments','Review bookings, status and clinic schedule',$canBook?'<a class="btn btn-primary" href="'.e(url('appointments/book.php')).'"><i class="bi bi-plus-lg me-1"></i>New appointment</a>':'');?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="section-title">Appointment list</h2><span class="small-muted"><?=count($rows)?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Token</th>
                    <th>Patient</th>
                    <th>Doctor / department</th>
                    <th>Date & time</th>
                    <th>Reason</th>
                    <th>Status</th><?php if(in_array($user['role'],['super_admin','admin','staff'],true)):?><th>Update
                    </th><?php endif;?>
                </tr>
            </thead>
            <tbody><?php foreach($rows as $a):?><tr>
                    <td><span class="badge text-bg-light border">#<?= (int)$a['token_number']?></span></td>
                    <td><strong><?=e($a['patient_name'])?></strong></td>
                    <td><?=e($a['doctor_name'])?><div class="small-muted"><?=e($a['department_name'])?></div>
                    </td>
                    <td><?=e($a['appointment_date'])?><div class="small-muted">
                            <?=e(substr($a['start_at'],0,5))?>–<?=e(substr($a['end_at'],0,5))?></div>
                    </td>
                    <td><?=e($a['reason'])?></td>
                    <td><?=status_badge($a['status'])?></td>
                    <?php if(in_array($user['role'],['super_admin','admin','staff'],true)):?><td>
                        <form method="post" action="<?=e(url('appointments/update-status.php'))?>" class="d-flex gap-1">
                            <?=csrf_field()?><input type="hidden" name="id" value="<?=$a['id']?>"><select
                                class="form-select form-select-sm" name="status"
                                aria-label="Appointment status"><?php foreach(['requested','confirmed','checked_in','in_consultation','completed','cancelled','no_show'] as $st):?>
                                <option value="<?=$st?>" <?=$a['status']===$st?'selected':''?>>
                                    <?=e(ucwords(str_replace('_',' ',$st)))?></option>
                                <?php endforeach;?></select><button class="btn btn-sm btn-outline-primary">Save</button>
                        </form>
                    </td><?php endif;?>
                </tr>
                <?php endforeach;?><?php if(!$rows)echo empty_row(in_array($user['role'],['super_admin','admin','staff'],true)?7:6,'No appointments found.');?>
            </tbody>
        </table>
    </div>
</div><?php require __DIR__.'/../includes/footer.php';?>
<?php
require_once __DIR__ . '/functions.php';
function isLoggedIn(): bool { return logged_in(); }
function getCurrentUser(): ?array { return current_user(); }
function requireLogin(): void { require_login(); }
function requireRole(array $allowedRoles): void { require_role($allowedRoles); }
function loginUser(string $email, string $password): bool {
    $s=db()->prepare('SELECT u.id,u.name,u.email,u.password_hash,u.status,r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? LIMIT 1');
    $s->execute([strtolower(trim($email))]); $u=$s->fetch();
    if (!$u || $u['status']!=='active' || !password_verify($password,$u['password_hash'])) return false;
    session_regenerate_id(true); $_SESSION['user_id']=(int)$u['id']; $_SESSION['user_name']=$u['name']; $_SESSION['user_email']=$u['email']; $_SESSION['user_role']=$u['role_name'];
    audit((int)$u['id'],'USER_LOGIN','users',(string)$u['id'],'Successful login'); return true;
}
function logAuditEvent(?int $userId,string $action,string $entityType,?string $entityId,?string $metadata): void { audit($userId,$action,$entityType,$entityId,$metadata); }

<?php
declare(strict_types=1);

$storagePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'kuliah-reminder-' . hash('sha256', __DIR__) . '.json';

function sendJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function withTaskStore(string $path, int $lockType, callable $callback, bool $save = false)
{
    $file = fopen($path, 'c+');
    if ($file === false) {
        throw new RuntimeException('Penyimpanan tugas tidak dapat dibuka.');
    }

    try {
        if (!flock($file, $lockType)) {
            throw new RuntimeException('Penyimpanan tugas tidak dapat dikunci.');
        }

        rewind($file);
        $contents = stream_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Penyimpanan tugas tidak dapat dibaca.');
        }

        $tasks = $contents === '' ? [] : json_decode($contents, true);
        if (!is_array($tasks) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Data tugas tersimpan tidak valid.');
        }

        $result = $callback($tasks);
        if ($save) {
            $encoded = json_encode(
                array_values($tasks),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($encoded === false || !ftruncate($file, 0) || !rewind($file)) {
                throw new RuntimeException('Data tugas tidak dapat disimpan.');
            }
            if (fwrite($file, $encoded) === false || !fflush($file)) {
                throw new RuntimeException('Data tugas tidak dapat disimpan.');
            }
        }

        return $result;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}

function readJsonBody(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        sendJson(400, ['error' => 'Permintaan tidak valid.']);
    }
    return $body;
}

function validateTaskFields(array $body): array
{
    $title = trim((string) ($body['title'] ?? ''));
    $subject = trim((string) ($body['subject'] ?? ''));
    $dueDate = (string) ($body['dueDate'] ?? '');

    if ($title === '' || strlen($title) > 180) {
        sendJson(422, ['error' => 'Nama tugas wajib diisi dan maksimal 180 karakter.']);
    }
    if ($subject === '' || strlen($subject) > 120) {
        sendJson(422, ['error' => 'Mata kuliah wajib diisi dan maksimal 120 karakter.']);
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $date->format('Y-m-d') !== $dueDate
    ) {
        sendJson(422, ['error' => 'Tanggal deadline tidak valid.']);
    }

    return ['title' => $title, 'subject' => $subject, 'dueDate' => $dueDate];
}

if (isset($_GET['api']) && $_GET['api'] === 'tasks') {
    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $tasks = withTaskStore($storagePath, LOCK_SH, function (&$tasks) {
                return $tasks;
            });
            sendJson(200, ['tasks' => $tasks]);
        }

        $body = readJsonBody();
        if ($method === 'POST' && ($body['action'] ?? '') === 'create') {
            $fields = validateTaskFields($body);
            $task = withTaskStore($storagePath, LOCK_EX, function (&$tasks) use ($fields) {
                $task = [
                    'id' => bin2hex(random_bytes(12)),
                    'title' => $fields['title'],
                    'subject' => $fields['subject'],
                    'dueDate' => $fields['dueDate'],
                    'completed' => false,
                ];
                $tasks[] = $task;
                return $task;
            }, true);
            sendJson(201, ['task' => $task]);
        }

        if ($method === 'POST' && ($body['action'] ?? '') === 'toggle') {
            $id = (string) ($body['id'] ?? '');
            $completed = $body['completed'] ?? null;
            if ($id === '' || !is_bool($completed)) {
                sendJson(422, ['error' => 'Data status tugas tidak valid.']);
            }
            $task = withTaskStore($storagePath, LOCK_EX, function (&$tasks) use ($id, $completed) {
                foreach ($tasks as &$item) {
                    if (($item['id'] ?? '') === $id) {
                        $item['completed'] = $completed;
                        $updated = $item;
                        unset($item);
                        return $updated;
                    }
                }
                unset($item);
                return null;
            }, true);
            if ($task === null) {
                sendJson(404, ['error' => 'Tugas tidak ditemukan.']);
            }
            sendJson(200, ['task' => $task]);
        }

        if ($method === 'DELETE') {
            $id = (string) ($body['id'] ?? '');
            if ($id === '') {
                sendJson(422, ['error' => 'ID tugas tidak valid.']);
            }
            $deleted = withTaskStore($storagePath, LOCK_EX, function (&$tasks) use ($id) {
                foreach ($tasks as $index => $item) {
                    if (($item['id'] ?? '') === $id) {
                        array_splice($tasks, $index, 1);
                        return true;
                    }
                }
                return false;
            }, true);
            if (!$deleted) {
                sendJson(404, ['error' => 'Tugas tidak ditemukan.']);
            }
            sendJson(200, ['success' => true]);
        }

        sendJson(405, ['error' => 'Metode permintaan tidak didukung.']);
    } catch (Throwable $error) {
        error_log('Kuliah Reminder: ' . $error->getMessage());
        sendJson(500, ['error' => 'Terjadi kesalahan saat mengakses data tugas.']);
    }
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f5f0">
    <title>Teman Deadline — Pengingat Tugas Kuliah</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #1f2925;
            --muted: #6d7771;
            --line: #e2e6df;
            --paper: #fff;
            --canvas: #f5f5f0;
            --green: #236746;
            --green-dark: #174d34;
            --green-soft: #e8f2eb;
            --orange: #b85b2d;
            --orange-soft: #fbefe8;
            --shadow: 0 12px 36px rgba(31, 41, 37, .055);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--canvas);
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 15px;
            line-height: 1.5;
        }
        button, input { font: inherit; }
        button { cursor: pointer; }
        .shell { width: min(1160px, calc(100% - 40px)); margin: 0 auto; }
        .topbar {
            min-height: 78px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 1px solid var(--line);
        }
        .brand { display: flex; align-items: center; gap: 11px; font-weight: 750; letter-spacing: -.03em; }
        .brand-mark {
            width: 38px; height: 38px; display: grid; place-items: center;
            border-radius: 12px; background: var(--green); color: #fff; font-size: 18px;
        }
        .brand small { display: block; color: var(--muted); font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; }
        .notify-button {
            display: inline-flex; align-items: center; gap: 9px; min-height: 42px;
            padding: 0 15px; border: 1px solid #cbd7cd; border-radius: 10px;
            background: var(--paper); color: var(--green-dark); font-weight: 650;
        }
        .notify-button:hover { background: var(--green-soft); }
        .notify-button:focus-visible, button:focus-visible, input:focus-visible { outline: 3px solid #95c7a4; outline-offset: 2px; }
        .hero { padding: 46px 0 28px; display: flex; align-items: end; justify-content: space-between; gap: 24px; }
        .eyebrow { margin: 0 0 8px; color: var(--green); font-size: 12px; font-weight: 750; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 0; font-size: clamp(30px, 4vw, 43px); line-height: 1.12; letter-spacing: -.055em; }
        .hero-copy { margin: 12px 0 0; color: var(--muted); max-width: 540px; }
        .today { color: var(--muted); font-size: 13px; text-align: right; white-space: nowrap; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 25px; }
        .stat {
            display: flex; align-items: center; justify-content: space-between; gap: 14px;
            min-height: 96px; padding: 20px 22px; border: 1px solid var(--line);
            border-radius: 15px; background: var(--paper);
        }
        .stat-label { display: block; color: var(--muted); font-size: 13px; font-weight: 600; }
        .stat-value { display: block; margin-top: 3px; font-size: 26px; font-weight: 760; letter-spacing: -.05em; }
        .stat-icon { width: 40px; height: 40px; display: grid; place-items: center; border-radius: 12px; background: var(--green-soft); color: var(--green); font-size: 18px; }
        .stat.warning .stat-icon { background: var(--orange-soft); color: var(--orange); }
        .workspace { display: grid; grid-template-columns: minmax(280px, 350px) minmax(0, 1fr); align-items: start; gap: 20px; padding-bottom: 54px; }
        .panel { border: 1px solid var(--line); border-radius: 16px; background: var(--paper); box-shadow: var(--shadow); }
        .form-panel { padding: 23px; position: sticky; top: 18px; }
        .panel-title { margin: 0; font-size: 17px; letter-spacing: -.025em; }
        .panel-subtitle { margin: 5px 0 20px; color: var(--muted); font-size: 13px; }
        .field { margin-bottom: 15px; }
        .field label { display: block; margin-bottom: 7px; font-size: 12px; font-weight: 700; }
        .field input {
            width: 100%; min-height: 45px; padding: 10px 12px; border: 1px solid var(--line);
            border-radius: 9px; background: #fff; color: var(--ink);
        }
        .field input::placeholder { color: #a0a8a2; }
        .field input:focus { border-color: #78a989; outline: 3px solid #e7f2e9; }
        .submit-button {
            width: 100%; min-height: 47px; border: 0; border-radius: 10px;
            background: var(--green); color: #fff; font-weight: 700;
        }
        .submit-button:hover { background: var(--green-dark); }
        .submit-button:disabled { opacity: .65; cursor: wait; }
        .form-hint { display: flex; gap: 8px; margin: 15px 0 0; color: var(--muted); font-size: 12px; }
        .form-hint span { color: var(--green); }
        .list-panel { min-height: 450px; overflow: hidden; }
        .list-header { padding: 22px 23px 17px; border-bottom: 1px solid var(--line); }
        .list-heading { display: flex; align-items: center; justify-content: space-between; gap: 15px; }
        .count-label { color: var(--muted); font-size: 12px; }
        .filters { display: flex; gap: 7px; margin-top: 17px; }
        .filter-button {
            padding: 7px 12px; border: 1px solid transparent; border-radius: 8px;
            background: transparent; color: var(--muted); font-size: 12px; font-weight: 650;
        }
        .filter-button:hover { background: #f5f6f2; }
        .filter-button.active { border-color: #d4e4d7; background: var(--green-soft); color: var(--green-dark); }
        .task-list { padding: 0 23px; }
        .task-card {
            display: grid; grid-template-columns: 22px minmax(0, 1fr) auto; align-items: start; gap: 14px;
            padding: 20px 0; border-bottom: 1px solid var(--line);
        }
        .task-card:last-child { border-bottom: 0; }
        .check-button {
            width: 21px; height: 21px; padding: 0; margin-top: 2px; border: 1.5px solid #bbc6bd;
            border-radius: 7px; background: #fff; color: #fff; line-height: 1;
        }
        .check-button:hover { border-color: var(--green); }
        .check-button.checked { border-color: var(--green); background: var(--green); }
        .task-title { margin: 0; font-size: 15px; font-weight: 700; overflow-wrap: anywhere; }
        .task-card.completed .task-title { color: #89928b; text-decoration: line-through; }
        .subject { display: inline-flex; margin-top: 7px; padding: 3px 8px; border-radius: 6px; background: #f1f3ef; color: #59665d; font-size: 11px; font-weight: 650; }
        .task-date { display: block; margin-top: 9px; color: var(--muted); font-size: 12px; }
        .task-meta { min-width: 98px; display: flex; flex-direction: column; align-items: end; gap: 8px; }
        .due-badge { padding: 4px 8px; border-radius: 7px; background: var(--green-soft); color: var(--green-dark); font-size: 11px; font-weight: 700; white-space: nowrap; }
        .due-badge.urgent, .due-badge.overdue { background: var(--orange-soft); color: var(--orange); }
        .due-badge.done { background: #f1f3ef; color: #707a73; }
        .delete-button { padding: 3px 0; border: 0; background: none; color: #929b94; font-size: 11px; }
        .delete-button:hover { color: #b64736; text-decoration: underline; }
        .empty-state { padding: 76px 25px; text-align: center; }
        .empty-icon { width: 56px; height: 56px; display: grid; place-items: center; margin: 0 auto 15px; border-radius: 17px; background: var(--green-soft); color: var(--green); font-size: 24px; }
        .empty-state h3 { margin: 0; font-size: 16px; }
        .empty-state p { margin: 7px auto 0; max-width: 285px; color: var(--muted); font-size: 13px; }
        .toast {
            position: fixed; right: 22px; bottom: 22px; z-index: 10; max-width: min(380px, calc(100% - 36px));
            padding: 13px 16px; border-radius: 11px; background: #202c25; color: #fff;
            box-shadow: 0 12px 34px rgba(0, 0, 0, .18); font-size: 13px;
        }
        .toast.error { background: #8b3429; }
        .toast[hidden] { display: none; }
        .loading { padding: 48px 20px; color: var(--muted); text-align: center; font-size: 13px; }

        @media (max-width: 760px) {
            .shell { width: min(100% - 28px, 560px); }
            .topbar { min-height: 68px; }
            .notify-button { min-height: 39px; padding: 0 11px; font-size: 12px; }
            .hero { align-items: start; flex-direction: column; padding: 34px 0 22px; gap: 12px; }
            .today { text-align: left; }
            .stats { gap: 9px; }
            .stat { min-height: 82px; padding: 13px 12px; }
            .stat-label { font-size: 11px; }
            .stat-value { font-size: 22px; }
            .stat-icon { width: 33px; height: 33px; font-size: 15px; }
            .workspace { grid-template-columns: 1fr; gap: 15px; }
            .form-panel { position: static; }
        }
        @media (max-width: 420px) {
            .brand { font-size: 13px; }
            .brand-mark { width: 34px; height: 34px; }
            .notify-button { max-width: 148px; line-height: 1.2; }
            .stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .stat { align-items: start; flex-direction: column-reverse; gap: 4px; }
            .stat-icon { display: none; }
            .task-card { grid-template-columns: 22px minmax(0, 1fr); gap: 10px; }
            .task-meta { grid-column: 2; align-items: start; flex-direction: row; flex-wrap: wrap; }
            .list-header { padding: 19px 16px 14px; }
            .task-list { padding: 0 16px; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">✓</span>
                <span>Teman Deadline<small>Pengingat tugas kuliah</small></span>
            </div>
            <button class="notify-button" id="notify-button" type="button">
                <span aria-hidden="true">♧</span><span id="notify-label">Aktifkan pengingat</span>
            </button>
        </header>

        <main>
            <section class="hero" aria-labelledby="page-title">
                <div>
                    <p class="eyebrow">Rapi kuliahnya, tenang pikirannya</p>
                    <h1 id="page-title">Semua tugas, satu tempat.</h1>
                    <p class="hero-copy">Catat tugas dan deadline, lalu dapatkan pengingat dua hari sebelum waktunya.</p>
                </div>
                <div class="today" id="today-label"></div>
            </section>

            <section class="stats" aria-label="Ringkasan tugas">
                <article class="stat">
                    <div><span class="stat-label">Tugas aktif</span><strong class="stat-value" id="active-count">—</strong></div>
                    <span class="stat-icon" aria-hidden="true">▤</span>
                </article>
                <article class="stat warning">
                    <div><span class="stat-label">Deadline 7 hari</span><strong class="stat-value" id="week-count">—</strong></div>
                    <span class="stat-icon" aria-hidden="true">◷</span>
                </article>
                <article class="stat">
                    <div><span class="stat-label">Selesai</span><strong class="stat-value" id="done-count">—</strong></div>
                    <span class="stat-icon" aria-hidden="true">✓</span>
                </article>
            </section>

            <section class="workspace">
                <aside class="panel form-panel" aria-labelledby="form-title">
                    <h2 class="panel-title" id="form-title">Tambah tugas baru</h2>
                    <p class="panel-subtitle">Jangan sampai ada deadline yang terlewat.</p>
                    <form id="task-form">
                        <div class="field">
                            <label for="title">Nama tugas</label>
                            <input id="title" name="title" maxlength="180" placeholder="Contoh: Makalah kelompok" required>
                        </div>
                        <div class="field">
                            <label for="subject">Mata kuliah</label>
                            <input id="subject" name="subject" maxlength="120" placeholder="Contoh: Algoritma dan Pemrograman" required>
                        </div>
                        <div class="field">
                            <label for="due-date">Tanggal deadline</label>
                            <input id="due-date" name="dueDate" type="date" required>
                        </div>
                        <button class="submit-button" id="submit-button" type="submit">＋ Tambahkan tugas</button>
                    </form>
                    <p class="form-hint"><span aria-hidden="true">◷</span> Pengingat otomatis dikirim H-2 saat situs terbuka.</p>
                </aside>

                <section class="panel list-panel" aria-labelledby="tasks-title">
                    <div class="list-header">
                        <div class="list-heading">
                            <h2 class="panel-title" id="tasks-title">Daftar tugas</h2>
                            <span class="count-label" id="list-count">Memuat...</span>
                        </div>
                        <div class="filters" role="group" aria-label="Filter tugas">
                            <button class="filter-button active" type="button" data-filter="active" aria-pressed="true">Belum selesai</button>
                            <button class="filter-button" type="button" data-filter="completed" aria-pressed="false">Selesai</button>
                        </div>
                    </div>
                    <div class="task-list" id="task-list" aria-live="polite">
                        <div class="loading">Menyiapkan daftar tugas...</div>
                    </div>
                </section>
            </section>
        </main>
    </div>
    <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

    <script>
        const apiUrl = `${window.location.pathname}?api=tasks`;
        const taskList = document.getElementById('task-list');
        const form = document.getElementById('task-form');
        const submitButton = document.getElementById('submit-button');
        const toast = document.getElementById('toast');
        const notificationKey = 'teman-deadline-notified-v1';
        let tasks = [];
        let activeFilter = 'active';
        let toastTimer;

        function localDateKey(date = new Date()) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        function dayNumber(dateString) {
            const [year, month, day] = dateString.split('-').map(Number);
            return Math.floor(Date.UTC(year, month - 1, day) / 86400000);
        }

        function daysUntil(dateString) {
            return dayNumber(dateString) - dayNumber(localDateKey());
        }

        function formatDate(dateString) {
            const [year, month, day] = dateString.split('-').map(Number);
            return new Intl.DateTimeFormat('id-ID', {
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            }).format(new Date(year, month - 1, day));
        }

        function showToast(message, isError = false) {
            toast.textContent = message;
            toast.classList.toggle('error', isError);
            toast.hidden = false;
            window.clearTimeout(toastTimer);
            toastTimer = window.setTimeout(() => { toast.hidden = true; }, 3600);
        }

        async function requestApi(method = 'GET', body = null) {
            const options = { method, headers: { Accept: 'application/json' } };
            if (body !== null) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }
            const response = await fetch(apiUrl, options);
            let result;
            try {
                result = await response.json();
            } catch {
                throw new Error('Respons server tidak dapat dibaca.');
            }
            if (!response.ok) {
                throw new Error(result.error || 'Permintaan gagal diproses.');
            }
            return result;
        }

        function updateNotificationButton() {
            const button = document.getElementById('notify-button');
            const label = document.getElementById('notify-label');
            if (!('Notification' in window)) {
                label.textContent = 'Notifikasi tidak tersedia';
                button.disabled = true;
                button.title = 'Browser ini tidak mendukung notifikasi.';
                return;
            }
            if (Notification.permission === 'granted') {
                label.textContent = 'Pengingat aktif';
                button.title = 'Notifikasi browser sudah diizinkan.';
            } else if (Notification.permission === 'denied') {
                label.textContent = 'Notifikasi diblokir';
                button.title = 'Ubah izin notifikasi untuk situs ini melalui pengaturan browser.';
            } else {
                label.textContent = 'Aktifkan pengingat';
                button.title = 'Izinkan notifikasi browser untuk pengingat H-2.';
            }
        }

        function checkReminders() {
            if (!('Notification' in window) || Notification.permission !== 'granted') return;
            let sent;
            try {
                sent = JSON.parse(localStorage.getItem(notificationKey) || '{}');
            } catch {
                sent = {};
            }
            let changed = false;
            for (const task of tasks) {
                if (task.completed || daysUntil(task.dueDate) !== 2) continue;
                const reminderId = `${task.id}:${task.dueDate}`;
                if (sent[reminderId]) continue;
                const notification = new Notification('Pengingat deadline — H-2', {
                    body: `${task.subject}: ${task.title} jatuh tempo ${formatDate(task.dueDate)}.`,
                    tag: `deadline-${task.id}`
                });
                notification.onclick = () => {
                    window.focus();
                    notification.close();
                };
                sent[reminderId] = true;
                changed = true;
            }
            if (changed) localStorage.setItem(notificationKey, JSON.stringify(sent));
        }

        function dueLabel(task) {
            if (task.completed) return { text: 'Selesai', className: 'done' };
            const remaining = daysUntil(task.dueDate);
            if (remaining < 0) return { text: `Terlambat ${Math.abs(remaining)} hari`, className: 'overdue' };
            if (remaining === 0) return { text: 'Hari ini', className: 'urgent' };
            if (remaining === 1) return { text: 'Besok', className: 'urgent' };
            if (remaining === 2) return { text: 'H-2', className: 'urgent' };
            return { text: `${remaining} hari lagi`, className: '' };
        }

        function makeTaskCard(task) {
            const card = document.createElement('article');
            card.className = `task-card${task.completed ? ' completed' : ''}`;

            const check = document.createElement('button');
            check.type = 'button';
            check.className = `check-button${task.completed ? ' checked' : ''}`;
            check.setAttribute('aria-label', task.completed ? 'Tandai tugas belum selesai' : 'Tandai tugas selesai');
            check.setAttribute('aria-pressed', String(task.completed));
            check.textContent = task.completed ? '✓' : '';
            check.addEventListener('click', () => toggleTask(task));

            const details = document.createElement('div');
            const title = document.createElement('h3');
            title.className = 'task-title';
            title.textContent = task.title;
            const subject = document.createElement('span');
            subject.className = 'subject';
            subject.textContent = task.subject;
            const date = document.createElement('time');
            date.className = 'task-date';
            date.dateTime = task.dueDate;
            date.textContent = `Deadline ${formatDate(task.dueDate)}`;
            details.append(title, subject, date);

            const meta = document.createElement('div');
            meta.className = 'task-meta';
            const due = dueLabel(task);
            const badge = document.createElement('span');
            badge.className = `due-badge ${due.className}`.trim();
            badge.textContent = due.text;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'delete-button';
            remove.textContent = 'Hapus';
            remove.setAttribute('aria-label', `Hapus tugas ${task.title}`);
            remove.addEventListener('click', () => deleteTask(task));
            meta.append(badge, remove);

            card.append(check, details, meta);
            return card;
        }

        function renderTasks() {
            const activeTasks = tasks.filter(task => !task.completed);
            const dueThisWeek = activeTasks.filter(task => {
                const remaining = daysUntil(task.dueDate);
                return remaining >= 0 && remaining <= 7;
            });
            document.getElementById('active-count').textContent = activeTasks.length;
            document.getElementById('week-count').textContent = dueThisWeek.length;
            document.getElementById('done-count').textContent = tasks.length - activeTasks.length;

            const visible = tasks
                .filter(task => activeFilter === 'completed' ? task.completed : !task.completed)
                .sort((a, b) => a.dueDate.localeCompare(b.dueDate));
            document.getElementById('list-count').textContent = `${visible.length} tugas`;
            taskList.replaceChildren();

            if (visible.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'empty-state';
                const icon = document.createElement('div');
                icon.className = 'empty-icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = activeFilter === 'completed' ? '✓' : '✦';
                const heading = document.createElement('h3');
                heading.textContent = activeFilter === 'completed'
                    ? 'Belum ada tugas selesai'
                    : tasks.length > 0 ? 'Semua tugas sudah beres!' : 'Belum ada tugas';
                const description = document.createElement('p');
                description.textContent = activeFilter === 'completed'
                    ? 'Tugas yang sudah selesai akan muncul di sini.'
                    : tasks.length > 0
                        ? 'Tugas yang sudah selesai tersimpan di tab Selesai.'
                        : 'Tambahkan tugas kuliah pertamamu lewat formulir di samping.';
                empty.append(icon, heading, description);
                taskList.append(empty);
            } else {
                const fragment = document.createDocumentFragment();
                visible.forEach(task => fragment.append(makeTaskCard(task)));
                taskList.append(fragment);
            }
            checkReminders();
        }

        async function loadTasks() {
            try {
                const result = await requestApi();
                tasks = result.tasks;
                renderTasks();
            } catch (error) {
                taskList.replaceChildren();
                const message = document.createElement('div');
                message.className = 'loading';
                message.textContent = error.message;
                taskList.append(message);
                showToast(error.message, true);
            }
        }

        async function toggleTask(task) {
            try {
                await requestApi('POST', {
                    action: 'toggle',
                    id: task.id,
                    completed: !task.completed
                });
                await loadTasks();
            } catch (error) {
                showToast(error.message, true);
            }
        }

        async function deleteTask(task) {
            if (!window.confirm(`Hapus tugas "${task.title}"?`)) return;
            try {
                await requestApi('DELETE', { id: task.id });
                tasks = tasks.filter(item => item.id !== task.id);
                renderTasks();
                showToast('Tugas berhasil dihapus.');
            } catch (error) {
                showToast(error.message, true);
            }
        }

        form.addEventListener('submit', async event => {
            event.preventDefault();
            submitButton.disabled = true;
            const data = new FormData(form);
            try {
                await requestApi('POST', {
                    action: 'create',
                    title: data.get('title'),
                    subject: data.get('subject'),
                    dueDate: data.get('dueDate')
                });
                form.reset();
                await loadTasks();
                showToast('Tugas berhasil ditambahkan.');
                document.getElementById('title').focus();
            } catch (error) {
                showToast(error.message, true);
            } finally {
                submitButton.disabled = false;
            }
        });

        document.querySelectorAll('.filter-button').forEach(button => {
            button.addEventListener('click', () => {
                activeFilter = button.dataset.filter;
                document.querySelectorAll('.filter-button').forEach(item => {
                    const selected = item === button;
                    item.classList.toggle('active', selected);
                    item.setAttribute('aria-pressed', String(selected));
                });
                renderTasks();
            });
        });

        document.getElementById('notify-button').addEventListener('click', async () => {
            if (!('Notification' in window)) {
                showToast('Browser ini tidak mendukung notifikasi.', true);
                return;
            }
            if (Notification.permission === 'denied') {
                showToast('Izinkan notifikasi Teman Deadline melalui pengaturan browser.', true);
                return;
            }
            try {
                const permission = await Notification.requestPermission();
                updateNotificationButton();
                if (permission === 'granted') {
                    showToast('Pengingat browser aktif. Situs perlu tetap terbuka.');
                    checkReminders();
                } else {
                    showToast('Izin notifikasi belum diberikan.', true);
                }
            } catch {
                showToast('Browser gagal meminta izin notifikasi.', true);
            }
        });

        document.getElementById('today-label').textContent = new Intl.DateTimeFormat('id-ID', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        }).format(new Date());
        updateNotificationButton();
        loadTasks();
        window.setInterval(loadTasks, 60000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) loadTasks();
        });
    </script>
</body>
</html>

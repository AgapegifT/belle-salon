<?php
/**
 * admin/services.php  -  add and edit the salon's services.
 * Services are never deleted (old appointments still point to them); untick "Active" to hide one from clients.
 */
require __DIR__ . '/../includes/bootstrap.php';
$me     = require_role('admin');
$title  = 'Services';
$active = 'services';
$pdo    = db();

if (is_post()) {
    csrf_check();
    $name = post('name'); $cat = post('category'); $price = post('price'); $dur = (int)post('duration');
    $errors = [];
    if ($name === '' || mb_strlen($name) > 100)  $errors[] = 'Service name is required.';
    if ($cat === '' || mb_strlen($cat) > 50)     $errors[] = 'Category is required (for example Hair or Nails).';
    if (!is_numeric($price) || $price <= 0)      $errors[] = 'Price must be a number above 0.';
    if ($dur < 10 || $dur > 480)                 $errors[] = 'Duration must be between 10 and 480 minutes.';

    if ($errors) {
        foreach ($errors as $e) flash('error', $e);
    } elseif (post('action') === 'add') {
        $pdo->prepare('INSERT INTO services (name, category, price, duration_minutes, is_active) VALUES (?, ?, ?, ?, 1)')
            ->execute([$name, $cat, $price, $dur]);
        flash('success', 'Service "' . $name . '" added.');
    } elseif (post('action') === 'save') {
        $pdo->prepare('UPDATE services SET name = ?, category = ?, price = ?, duration_minutes = ?, is_active = ? WHERE service_id = ?')
            ->execute([$name, $cat, $price, $dur, isset($_POST['is_active']) ? 1 : 0, (int)post('id')]);
        flash('success', 'Service "' . $name . '" saved.');
    }
    redirect('admin/services.php');
}

$services = $pdo->query('SELECT * FROM services ORDER BY category, name')->fetchAll();
$cats = array_column($pdo->query('SELECT DISTINCT specialization FROM staff ORDER BY specialization')->fetchAll(), 'specialization');

require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><h1>Services</h1></div>
<datalist id="cats"><?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>

<div class="card">
  <h2>Add a service</h2>
  <form method="post" class="svc-row">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div><label>Name</label><input type="text" name="name" required></div>
    <div><label>Category</label><input type="text" name="category" list="cats" required></div>
    <div><label>Price (RWF)</label><input type="number" name="price" min="1" step="1" required></div>
    <div><label>Minutes</label><input type="number" name="duration" min="10" max="480" step="5" required></div>
    <div></div><button class="btn">Add</button>
  </form>
  <p class="hint">A client can only pick stylists whose specialization matches the category.</p>
</div>

<div class="card">
  <h2>All services</h2>
  <?php foreach ($services as $s): ?>
    <form method="post" class="svc-row" style="padding:.7rem 0;border-bottom:1px solid var(--line)">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$s['service_id'] ?>">
      <div><label>Name</label><input type="text" name="name" value="<?= h($s['name']) ?>" required></div>
      <div><label>Category</label><input type="text" name="category" value="<?= h($s['category']) ?>" list="cats" required></div>
      <div><label>Price (RWF)</label><input type="number" name="price" value="<?= h((int)$s['price']) ?>" min="1" required></div>
      <div><label>Minutes</label><input type="number" name="duration" value="<?= (int)$s['duration_minutes'] ?>" min="10" max="480" step="5" required></div>
      <div><label><input type="checkbox" name="is_active" <?= $s['is_active'] ? 'checked' : '' ?>> Active</label></div>
      <button class="btn btn-small btn-light">Save</button>
    </form>
  <?php endforeach; ?>
  <?php if (!$services): ?><p class="empty">No services yet.</p><?php endif; ?>
</div>
<style>
  .svc-row { display: grid; grid-template-columns: 2fr 1.3fr 1fr .9fr auto auto; gap: .6rem; align-items: end; }
  .svc-row label { font-size: .8rem; color: var(--muted); }
  @media (max-width: 860px) { .svc-row { grid-template-columns: 1fr 1fr; } }
</style>
<?php require __DIR__ . '/../includes/footer.php'; ?>

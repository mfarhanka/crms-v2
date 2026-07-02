<h6 class="text-muted mb-3">Basic Information</h6>

<div class="mb-3">
    <label class="form-label">Full Name <span class="text-danger">*</span></label>
    <input type="text" class="form-control" name="full_name" value="<?php echo $customer_form['full_name']; ?>" required>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Email</label>
        <input type="email" class="form-control" name="email" value="<?php echo $customer_form['email']; ?>">
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">Phone <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="phone" value="<?php echo $customer_form['phone']; ?>" required>
    </div>
</div>

<div class="mb-3">
    <label class="form-label">Address</label>
    <textarea class="form-control" name="address" rows="2"><?php echo $customer_form['address']; ?></textarea>
</div>

<hr class="my-4">
<h6 class="text-muted mb-3">Document Information</h6>

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">IC Number</label>
        <input type="text" class="form-control" name="id_number" value="<?php echo $customer_form['id_number']; ?>">
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">Driving License Number</label>
        <input type="text" class="form-control" name="license_number" value="<?php echo $customer_form['license_number']; ?>">
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Driving License Expiry Date</label>
        <input type="date" class="form-control" name="license_expiry_date" value="<?php echo $customer_form['license_expiry_date']; ?>">
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">PSV Expiry Date</label>
        <input type="date" class="form-control" name="psv_expiry_date" value="<?php echo $customer_form['psv_expiry_date']; ?>">
    </div>
</div>

<hr class="my-4">
<h6 class="text-muted mb-3">Document Photos <small class="text-muted">(JPG, PNG, PDF - Max 5MB)</small></h6>

<?php
$document_fields = [
    'ic_front_photo' => 'IC Front Photo',
    'ic_back_photo' => 'IC Back Photo',
    'license_front_photo' => 'Driving License Front Photo',
    'license_back_photo' => 'Driving License Back Photo',
    'psv_front_photo' => 'PSV Front Photo',
    'psv_back_photo' => 'PSV Back Photo',
];
$document_index = 0;
?>

<?php foreach ($document_fields as $field_name => $field_label): ?>
<?php if ($document_index % 2 == 0): ?>
<div class="row">
<?php endif; ?>
    <div class="col-md-6 mb-3">
        <label class="form-label"><?php echo $field_label; ?></label>
        <?php if (!empty($customer_form[$field_name])): ?>
        <div class="mb-2">
            <a href="uploads/customers/<?php echo $customer_form[$field_name]; ?>" target="_blank" class="btn btn-sm btn-info">
                <i class="bi bi-file-earmark-image"></i> View Current
            </a>
        </div>
        <?php endif; ?>
        <input type="file" class="form-control" name="<?php echo $field_name; ?>" accept="image/*,.pdf">
    </div>
<?php $document_index++; ?>
<?php if ($document_index % 2 == 0): ?>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($document_index % 2 != 0): ?>
</div>
<?php endif; ?>

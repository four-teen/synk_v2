<?php
if (!isset($profileEditor,$errors,$today) || $profileEditor['additional_degrees'] === null) { http_response_code(404); return; }

function faculty_profile_additional_degree(string $index,array $row,array $errors,string $today,int $number=2): void
{
    $prefix='additional_degree_'.$index.'_';
    $numberLabel=$index==='__INDEX__'?'__NUMBER__':(string)$number;
    $level=$row['level']??'bachelor';
    $levelLabels=['bachelor'=>"Bachelor's degree",'master'=>"Master's degree",'doctoral'=>'Doctoral degree'];
    $label=$level==='__LEVEL__'?'__LABEL__':($levelLabels[$level]??"Bachelor's degree");
    $base=synk_faculty_profile_fields();$fields=[];$values=[];
    foreach (['status','degree','institution','address','specialization'] as $column) {
        $key=$prefix.$column;$fields[$key]=$base['bachelor_'.$column];
        $fields[$key]['name']='additional_degrees['.$index.']['.$column.']';
        if ($column==='status') { $fields[$key]['options']=['Completed','Ongoing']; $fields[$key]['required']=true; }
        if ($column==='degree') $fields[$key]['required']=true;
        $values[$key]=$row[$column]??'';
    }
    ?>
    <fieldset class="profile-education-level profile-additional-degree" data-additional-degree data-degree-index="<?= synk_faculty_profile_h($index) ?>">
      <legend><span class="profile-additional-degree-title"><?= synk_faculty_profile_h($label.' '.$numberLabel) ?></span><button type="button" class="btn btn-sm btn-outline-danger" data-remove-degree aria-label="<?= synk_faculty_profile_h('Remove '.$label.' '.$numberLabel) ?>"><i class="bx bx-trash" aria-hidden="true"></i>Remove</button></legend>
      <input type="hidden" id="<?= $prefix ?>level" name="additional_degrees[<?= synk_faculty_profile_h($index) ?>][level]" value="<?= synk_faculty_profile_h($level) ?>">
      <?php if(isset($errors[$prefix.'level'])): ?><p class="alert alert-danger" id="<?= $prefix ?>level-error" role="alert"><?= synk_faculty_profile_h($errors[$prefix.'level']) ?> Remove this entry and add it under the correct degree section.</p><?php endif; ?>
      <div class="row g-4">
        <div class="col-md-6"><?php faculty_profile_input($prefix.'status',$fields,$values,$errors,$today); ?></div>
        <div class="col-12"><?php faculty_profile_input($prefix.'degree',$fields,$values,$errors,$today); ?></div>
        <div class="col-md-6"><?php faculty_profile_input($prefix.'institution',$fields,$values,$errors,$today); ?></div>
        <div class="col-md-6"><?php faculty_profile_input($prefix.'address',$fields,$values,$errors,$today); ?></div>
        <div class="col-12"><?php faculty_profile_input($prefix.'specialization',$fields,$values,$errors,$today); ?></div>
      </div>
    </fieldset>
    <?php
}

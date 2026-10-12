<?php

function synk_faculty_profile_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function faculty_profile_input(string $key, array $fields, array $profile, array $errors, string $today, string $help = ''): void
{
    $field = $fields[$key];
    $value = (string)($profile[$key] ?? '');
    if (in_array($key, ['campus_name', 'college_name'], true)) {
        $value = trim($value);
    }
    $error = $errors[$key] ?? '';
    $inputClass = isset($field['options']) ? 'form-select' : 'form-control';
    $attributes = ' id="' . $key . '" name="' . $key . '" class="' . $inputClass . ($error !== '' ? ' is-invalid' : '') . '"';
    if (isset($field['education_type'])) {
        $attributes .= ' data-education-type="' . $field['education_type'] . '" data-field-label="' . synk_faculty_profile_h($field['label']) . '"';
        $help = 'Select a saved entry or type a new one and press Enter.';
    }
    if (!empty($field['required'])) {
        $attributes .= ' required';
    }
    if (!empty($field['readonly'])) {
        $attributes .= ' readonly';
    }
    if (isset($field['max']) && !isset($field['options'])) {
        $attributes .= ' maxlength="' . $field['max'] . '"';
    }
    if (isset($field['placeholder'])) {
        $attributes .= ' placeholder="' . synk_faculty_profile_h($field['placeholder']) . '"';
    }
    $describedBy = [];
    if ($help !== '') {
        $describedBy[] = $key . '-help';
    }
    if ($error !== '') {
        $describedBy[] = $key . '-error';
        $attributes .= ' aria-invalid="true"';
    }
    if ($describedBy) {
        $attributes .= ' aria-describedby="' . implode(' ', $describedBy) . '"';
    }
    echo '<label class="form-label" for="' . $key . '">' . synk_faculty_profile_h($field['label']);
    if (!empty($field['required'])) {
        echo ' <span class="text-danger" aria-hidden="true">*</span>';
    }
    echo '</label>';
    if (isset($field['options'])) {
        echo '<select' . $attributes . '><option value="">Select ' . synk_faculty_profile_h(strtolower($field['label'])) . '</option>';
        if (in_array($key, ['campus_name', 'college_name'], true) && $value !== '' && !in_array($value, $field['options'], true)) {
            echo '<option value="' . synk_faculty_profile_h($value) . '" selected>' . synk_faculty_profile_h($value . ' (select a current option)') . '</option>';
        }
        foreach ($field['options'] as $option) {
            $rank = $field['rank_options'][$option] ?? null;
            $optionAttributes = $rank ? ' data-salary-grade="' . synk_faculty_profile_h($rank['salary_label']) . '"' : '';
            if (isset($field['campus_options'])) {
                $optionAttributes .= ' data-campuses="' . synk_faculty_profile_h(json_encode($field['campus_options'][$option], JSON_UNESCAPED_UNICODE)) . '"';
            }
            $optionLabel = $rank ? $option . ' — ' . (!empty($rank['legacy']) ? 'Previously saved' : $rank['salary_label']) : $option;
            echo '<option value="' . synk_faculty_profile_h($option) . '"' . ($value === $option ? ' selected' : '') . $optionAttributes . '>' . synk_faculty_profile_h($optionLabel) . '</option>';
        }
        echo '</select>';
    } elseif (!empty($field['textarea'])) {
        echo '<textarea' . $attributes . ' rows="3">' . synk_faculty_profile_h($value) . '</textarea>';
    } else {
        $type = !empty($field['date']) ? 'date' : 'text';
        if ($type === 'date') {
            $attributes .= ' min="1900-01-01" max="' . $today . '"';
        }
        if ($key === 'length_of_service' && !empty($profile['service_start_date'])) {
            $attributes .= ' readonly';
        }
        echo '<input type="' . $type . '"' . $attributes . ' value="' . synk_faculty_profile_h($value) . '">';
    }
    if ($error !== '') {
        echo '<div class="invalid-feedback" id="' . $key . '-error">' . synk_faculty_profile_h($error) . '</div>';
    }
    if ($help !== '') {
        echo '<div class="form-text" id="' . $key . '-help">' . synk_faculty_profile_h($help) . '</div>';
    }
}

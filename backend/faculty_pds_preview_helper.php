<?php
require_once __DIR__.'/hr_faculty_pds_helper.php';
// Guided input definitions for the faculty HR Personal Data Sheet.
function synk_faculty_pds_preview_sections(): array
{
    $field = static function (string $key, string $label, string $type = 'text', array $extra = []): array {
        return ['key'=>$key, 'label'=>$label, 'type'=>$type] + $extra;
    };
    $select = static function (string $key, string $label, array $options, array $extra = []) use ($field): array {
        return $field($key, $label, 'select', ['options'=>$options] + $extra);
    };
    $group = static function (string $label, array $fields, array $extra = []): array {
        return ['type'=>'group', 'label'=>$label, 'fields'=>$fields] + $extra;
    };
    $list = static function (string $key, string $label, string $singular, array $fields): array {
        return ['type'=>'list', 'key'=>$key, 'label'=>$label, 'singular'=>$singular, 'fields'=>$fields];
    };
    $names = static function (string $prefix = '') use ($field): array {
        return [$field($prefix.'surname', 'Surname'), $field($prefix.'first_name', 'First name'),
            $field($prefix.'middle_name', 'Middle name'), $field($prefix.'name_extension', 'Name extension', 'text', ['hint'=>'For example, Jr., Sr., or III.'])];
    };
    $address = static function (string $prefix) use ($field): array {
        $fields = [];
        foreach (['house'=>'House / block / lot number','street'=>'Street','village'=>'Subdivision / village',
            'barangay'=>'Barangay','city'=>'City / municipality','province'=>'Province','zip'=>'ZIP code'] as $key=>$label) {
            $fields[] = $field($prefix.$key, $label);
        }
        return $fields;
    };
    $dates = static function () use ($field): array {
        return [$field('date_from','From','date'), $field('date_to','To','date')];
    };
    $sections = [
        ['key'=>'personal','title'=>'About you','icon'=>'bx-user','description'=>'Start with your name and a few personal details.', 'groups'=>[
            $group('Your name', $names(), ['hint'=>'Prefilled from your profile name. Check the name parts and update anything that needs correcting.']),
            $group('Personal details', [
                $field('date_of_birth','Date of birth','date', ['max'=>'today']), $field('place_of_birth','Place of birth'),
                $select('sex_at_birth','Sex at birth',['Male','Female']),
                $select('civil_status','Civil status',['Single','Married','Widowed','Separated','Other']),
                $field('civil_status_other','Please specify civil status','text',['when'=>['civil_status','Other']]),
                $field('height','Height (m)','number',['step'=>'0.01','min'=>'0']),
                $field('weight','Weight (kg)','number',['step'=>'0.01','min'=>'0']),
                $select('blood_type','Blood type',['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'])]),
            $group('Citizenship', [
                $select('citizenship','Citizenship',['Filipino','Dual Citizenship']),
                $select('citizenship_basis','Basis of dual citizenship',['By birth','By naturalization'],['when'=>['citizenship','Dual Citizenship']]),
                $field('citizenship_country','Other country of citizenship','text',['when'=>['citizenship','Dual Citizenship']])])]],
        ['key'=>'contact','title'=>'Contact & IDs','icon'=>'bx-map','description'=>'Add your addresses, contact details, and identification numbers.', 'groups'=>[
            $group('Residential address', $address('residential_')),
            $group('Permanent address', array_merge([$field('same_address','Same as my residential address','checkbox')], $address('permanent_'))),
            $group('Contact details', [
                $field('telephone','Telephone number','tel'), $field('mobile','Mobile number','tel'),
                $field('email','PDS contact email','email',['hint'=>'This does not change your Google sign-in email.'])]),
            $group('Government & agency identifiers', [
                $field('umid','UMID ID number'), $field('pagibig','Pag-IBIG ID number'),
                $field('philhealth','PhilHealth number'), $field('philsys_pcn','PhilSys Card Number (PCN)'),
                $field('tin','TIN'), $field('agency_employee_number','Agency employee number','text',
                    ['hint'=>'Use the official number issued by your agency. It is separate from your Google email.'])],
                ['hint'=>'Enter identifiers exactly as issued, including any leading zeros.'])]],
        ['key'=>'family','title'=>'Family','icon'=>'bx-group','description'=>'Add the family information that applies to you.', 'groups'=>[
            $group('Spouse', [$select('spouse_applies','Do you need to provide spouse details?',['Yes','Not applicable'])]),
            $group('Spouse details', [$list('spouses','Spouse records','spouse record',array_merge($names(),[
                $field('occupation','Occupation'), $field('employer','Employer / business name'),
                $field('business_address','Business address'), $field('telephone','Telephone number','tel')]))], ['when'=>['spouse_applies','Yes']]),
            $group("Father's name", $names('father_'), ['collapsible'=>true]),
            $group("Mother's maiden name", $names('mother_'), ['collapsible'=>true]),
            $group('Children', [$select('children_apply','Do you have children to list?',['Yes','None'])]),
            $group('Children to list', [$list('children','Children','child',[
                $field('full_name','Full name'),$field('date_of_birth','Date of birth','date',['max'=>'today'])])], ['when'=>['children_apply','Yes']])]],
        ['key'=>'education','title'=>'Education','icon'=>'bxs-graduation','description'=>'Review your education one record at a time. Add earlier schooling and any missing details.', 'groups'=>[
            $list('education','Educational background','education record',[
                $select('level','Level',['Elementary','Secondary','Vocational / trade course','College','Graduate studies']),
                $field('institution','Name of school'),$field('degree','Basic education / degree / course'),
                $field('year_from','Attendance from (year)','number',['min'=>'1900','step'=>'1']),
                $field('year_to','Attendance to (year)','number',['min'=>'1900','step'=>'1']),
                $field('units','Highest level / units earned, if not graduated'),
                $field('year_graduated','Year graduated','number',['min'=>'1900','step'=>'1']),
                $field('honors','Scholarship / academic honors received'),
                $select('status','Study status',['Completed','Ongoing']),
                $field('address','School address (faculty profile)'),$field('specialization','Specialization (faculty profile)')])]],
        ['key'=>'eligibility','title'=>'Eligibility','icon'=>'bx-badge-check','description'=>'Complete the details of each eligibility or professional license.', 'groups'=>[
            $list('eligibilities','Civil service eligibility','eligibility',[
                $field('name','Career service / eligibility / professional license'),
                $field('rating','Rating, if applicable'),$field('examination_date','Date of examination / conferment','date'),
                $field('examination_place','Place of examination / conferment'),$field('license_number','License number, if applicable'),
                $field('valid_until','License validity date','date')])]],
        ['key'=>'work','title'=>'Work history','icon'=>'bx-briefcase-alt','description'=>'Include public and private employment, starting with your most recent work.', 'groups'=>[
            $list('work','Work experience','work record',[
                $field('position','Position title'),$field('employer','Department / agency / office / company'),
                $field('date_from','From','date'),$field('current','I currently work here','checkbox'),
                $field('date_to','To','date',['when'=>['current','unchecked']]),
                $field('salary','Monthly salary','number',['min'=>'0','step'=>'0.01']),
                $field('pay_grade','Salary / job / pay grade and step','text',['hint'=>'Use the recorded grade and step, for example 23-1.']),
                $field('appointment_status','Status of appointment'),$select('government_service','Government service',['Yes','No']),
                $group('Duties & accomplishments',[
                    $field('office_unit','Division / office / unit'),$field('supervisor','Immediate supervisor'),
                    $field('duties','Summary of actual duties','textarea'),$field('accomplishments','Accomplishments and contributions','textarea')],['collapsible'=>true])]),
            $group('Work Experience Sheet details', [], ['hint'=>'Each work record also has optional office, supervisor, duties, and accomplishments fields.'])]],
        ['key'=>'learning','title'=>'Learning & development','icon'=>'bx-book-reader','description'=>'Reuse your training records and add the PDS details to each one.', 'groups'=>[
            $list('trainings','Training programs attended','training',array_merge([
                $field('training_title','Title of learning & development intervention / training program')],$dates(),[
                $field('hours','Number of hours','number',['min'=>'0','step'=>'0.25']),
                $select('type','Type of L&D',['Managerial','Supervisory','Technical','Other']),
                $field('type_other','Please specify L&D type','text',['when'=>['type','Other']]),
                $field('agency','Conducted / sponsored by'),$field('venue','Venue (faculty profile)'),
                $select('mode','Mode (faculty profile)',['Face to face','Online'])]))]],
        ['key'=>'voluntary','title'=>'Voluntary work','icon'=>'bx-heart','description'=>'Record your involvement in civic, nongovernment, or voluntary organizations.', 'groups'=>[
            $group('Your involvement',[$select('voluntary_applies','Do you have voluntary work to list?',['Yes','None'])]),
            $group('Voluntary work records',[$list('voluntary','Voluntary work','voluntary work record',array_merge([
                $field('organization','Name of organization'),$field('address','Address of organization')],$dates(),[
                $field('hours','Number of hours','number',['min'=>'0','step'=>'0.25']),$field('role','Position / nature of work')]))],['when'=>['voluntary_applies','Yes']])]],
        ['key'=>'other','title'=>'Other details & references','icon'=>'bx-list-ul','description'=>'Add a few short entries for each applicable list.', 'groups'=>[
            $list('skills','Special skills & hobbies','skill or hobby',[$field('name','Special skill / hobby')]),
            $list('recognition','Nonacademic distinctions / recognition','recognition',[$field('name','Distinction / recognition')]),
            $list('memberships','Association / organization memberships','membership',[$field('name','Association / organization')]),
            $list('references','References (not related to you by consanguinity or affinity)','reference',[$field('name','Full name'),$field('address','Office / residential address'),
                $field('contact','Contact number and/or email')])]],
    ];
    $related = 'Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau or office or to the person who has immediate supervision over you in the Office, Bureau or Department where you will be appointed, ';
    $questions = [
        ['34a',$related.'within the third degree?'],
        ['34b',$related.'within the fourth degree (for Local Government Unit career employees)?'],
        ['35a','Have you ever been found guilty of any administrative offense?'],
        ['35b','Have you been criminally charged before any court?'],
        ['36','Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?'],
        ['37','Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?'],
        ['38a','Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?'],
        ['38b','Have you resigned from government service during the three-month period before the last election to promote or actively campaign for a national or local candidate?'],
        ['39','Have you acquired the status of an immigrant or permanent resident of another country?'],
        ['40a','Are you a member of any indigenous group?'],
        ['40b','Are you a person with disability?'],
        ['40c','Are you a solo parent?'],
    ];
    $declarations = [];
    foreach ($questions as [$number,$question]) {
        $details = [$field('q'.$number.'_details','If yes, give details','textarea',['when'=>['q'.$number,'Yes']])];
        if ($number === '35b') $details = array_merge($details,[
            $field('case_date','Date filed','date',['when'=>['q35b','Yes']]),
            $field('case_status','Status of case(s)','text',['when'=>['q35b','Yes']])]);
        if ($number === '39') $details = [$field('resident_country','Country','text',['when'=>['q39','Yes']])];
        if ($number === '40a') $details = [$field('indigenous_group','Please specify the indigenous group','text',['when'=>['q40a','Yes']])];
        if (in_array($number,['40b','40c'],true)) $details = [$field('q'.$number.'_id','Please specify ID number','text',['when'=>['q'.$number,'Yes']])];
        $declarations[] = $group($number.'. '.$question,array_merge([
            $field('q'.$number,'Your answer','answer')],$details),['question'=>true]);
    }
    $sections[] = ['key'=>'declarations','title'=>'Declarations','icon'=>'bx-check-shield',
        'description'=>'Answer each question yourself. Follow-up fields appear only when needed.', 'groups'=>$declarations];
    $signatures = [];
    for ($page=1;$page<=4;$page++) $signatures = array_merge($signatures,[
        $field('signature_page_'.$page,'Page '.$page.' signature / digital certificate','file',['accept'=>'.png,.jpg,.jpeg,.pdf,.p7s']),
        $field('signature_date_'.$page,'Page '.$page.' signature date','date')]);
    $sections[] = ['key'=>'certification','title'=>'Photo & final review','icon'=>'bx-file','description'=>'Save your photo, certification details, and supporting files when you are ready.', 'groups'=>[
        $group('PDS photo',[$field('photo','Passport-sized unfiltered digital photo','file',
            ['accept'=>'image/jpeg,image/png,image/webp','hint'=>'Use a recent photo taken within the last six months, 4.5 cm × 3.5 cm. Up to 2 MB.']),
            $field('photo_taken_date','Date the photo was taken','date')]),
        $group('Government-issued ID used for certification',[
            $field('certification_id_type','Government-issued ID'),$field('certification_id_number','ID / license / passport number'),
            $field('certification_id_date','Date of issuance','date'),$field('certification_id_place','Place of issuance'),
            $field('date_accomplished','Date accomplished','date')]),
        $group('Signatures & thumbmark',array_merge($signatures,[$field('thumbmark','Right thumbmark','file',['accept'=>'image/jpeg,image/png'])]),
            ['collapsible'=>true,'hint'=>'Files are uploaded when you save this section. Up to 2 MB per file and 6 MB per save. An upload stores the file; it does not verify a digital signature.']),
        $group('Oath administration',[
            $field('oath_date','Date of oath','date'),$field('oath_officer','Person administering oath'),
            $field('oath_signature','Oath administrator signature / certificate','file',['accept'=>'.png,.jpg,.jpeg,.pdf,.p7s'])],
            ['collapsible'=>true,'hint'=>'Record details from your administered oath and upload the existing signed document. The PDS excludes a notary public from electronic signing in this area. Saving does not administer an oath.']),
        $group('Declaration under oath',[$field('declaration_acknowledged','I have read and acknowledge this declaration.','checkbox')],['hint'=>'I declare under oath that I have personally accomplished this Personal Data Sheet which is a true, correct, and complete statement pursuant to the provisions of pertinent laws, rules, and regulations of the Republic of the Philippines. I authorize the agency head/authorized representative to verify/validate the contents stated herein. I agree that any misrepresentation made in this document and its attachments shall cause the filing of administrative/criminal case/s against me.']),
    ]];
    return $sections;
}

function synk_faculty_pds_preview_prefill(array $saved, array $degrees, array $trainings, array $catalog, string $email, ?array $faculty = null): array
{
    $values = synk_hr_pds_name_defaults((string)($saved['full_name'] ?? ''),$faculty) + ['email'=>$email];
    foreach (['date_of_birth','civil_status'] as $key) if (!empty($saved[$key])) $values[$key] = $saved[$key];
    if (!empty($values['civil_status']) && !in_array($values['civil_status'],['Single','Married','Widowed','Separated','Other'],true)) {
        $values['civil_status_other'] = $values['civil_status']; $values['civil_status'] = 'Other';
    }
    $education = [];
    foreach (['bachelor'=>'College','master'=>'Graduate studies','doctoral'=>'Graduate studies'] as $prefix=>$level) {
        if (empty($saved[$prefix.'_degree'])) continue;
        $record = ['level'=>$level];
        foreach (['degree','institution','address','specialization','status'] as $key) $record[$key] = (string)($saved[$prefix.'_'.$key] ?? '');
        $education[] = $record;
    }
    foreach ($degrees as $degree) {
        $degree['level'] = ($degree['level'] ?? '') === 'bachelor' ? 'College' : 'Graduate studies';
        $education[] = $degree;
    }
    $eligibilities = [];
    foreach (($saved['eligibility_ids'] ?? []) as $id) {
        if (isset($catalog[$id])) $eligibilities[] = ['name'=>(string)$catalog[$id]['name']];
    }
    if (!empty($saved['eligibility'])) $eligibilities[] = ['name'=>(string)$saved['eligibility']];
    return ['values'=>$values,'lists'=>['education'=>$education,'eligibilities'=>$eligibilities,'trainings'=>$trainings]];
}

<?php
// Offline UI boundary checks. No database, authentication session, or stored draft is used.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/faculty_profile_helper.php';
require_once __DIR__.'/../backend/faculty_profile_research_helper.php';
require_once __DIR__.'/../backend/faculty_profile_training_helper.php';
require_once __DIR__.'/../backend/hr_faculty_pds_helper.php';
$today = new DateTimeImmutable('2026-11-18');
$account = ['username'=>'Sample Faculty','email'=>'faculty@example.edu'];
$portal = ['faculty_is_linked'=>true];
$ranks = ['Instructor I'=>['salary_grade'=>12,'salary_label'=>'SG 12']];
$locations = ['Sample Campus'=>['Sample College']];
$profile = synk_faculty_profile_defaults($account,null);
$profile = array_merge($profile,['full_name'=>'Sample Faculty','faculty_id_number'=>'000123','gender'=>'Female',
    'date_of_birth'=>'1990-04-12','civil_status'=>'Single','campus_name'=>'Sample Campus','college_name'=>'Sample College',
    'faculty_rank'=>'Instructor I','salary_grade'=>'SG 12','employment_classification'=>'Permanent','length_of_service'=>'3 years',
    'bachelor_status'=>'Completed','bachelor_degree'=>'Bachelor of Science in Computer Science','bachelor_institution'=>'Sample University',
    'bachelor_address'=>'Sample City','bachelor_specialization'=>'Computer Science','master_status'=>'Not applicable','doctoral_status'=>'Not applicable',
    'revision'=>1,'updated_at'=>'2026-11-18 09:00:00']);
$storedProfile = $profile;
$fields = synk_faculty_profile_fields($ranks,$profile,$locations);
$eligibilities = [];
$errors = []; $saveError = ''; $savedNotice = in_array('--notice',$argv,true) ? 'Your faculty profile has been saved.' : ''; $age = '36 years old'; $initials = 'SF'; $profileImageUrl = '';
if(in_array('--photo',$argv,true))$profileImageUrl='/synk/assets/img/avatars/1.png';
$educationLevels = ['bachelor'=>"Bachelor's degree",'master'=>"Master's degree",'doctoral'=>'Doctoral degree'];
$completionRules = synk_faculty_profile_completion_rules($fields);
$completion = synk_faculty_profile_completion($profile,$completionRules);
$completionSections = ['personal'=>'Personal details','employment'=>'Employment','education'=>'Education'];
$professorSidebarCompletion = $completion;
$profileOwner = 'account:1';
$_SERVER['PHP_SELF'] = '/synk/professor/manage-profile.php';
$_SESSION = ['faculty_profile_csrf'=>'offline-preview-token'];
$profileEditor = ['show_profile_tabs'=>true,'show_completion_panel'=>false,'show_pds_preview'=>true,'show_portfolio_tabs'=>false,'active_tab'=>'pds',
    'navbar_path'=>__DIR__.'/fixtures/faculty-pds-preview-navbar.php','action'=>'#',
    'additional_degrees'=>[], 'hidden_fields'=>['profile_owner'=>'account:1'],
    'research_state'=>['records'=>[],'record'=>synk_faculty_profile_research_defaults(),'notice'=>'','error'=>'','errors'=>[],'show_editor'=>false],
    'training_state'=>['records'=>[['training_title'=>'Teaching with digital tools','agency'=>'Sample University','date_from'=>'2026-09-01',
        'date_to'=>'2026-09-02','venue'=>'Sample Campus','mode'=>'Face to face','training_id'=>1,'revision'=>1]],
        'record'=>synk_faculty_profile_training_defaults(),'notice'=>'','error'=>'','errors'=>[],'show_editor'=>false],
    'extra_styles'=>['manage-profile.css','profile-feedback.css','pds-preview.css'],
    'extra_scripts'=>['../assets/js/sweetalert2.all.min.js','profile-feedback.js','manage-profile.js','pds-preview.js']];
if (in_array('--hr',$argv,true)) {
    $storedProfile['full_name']='ELBREN OSORIO ANTONIO JR.';
    $profileEditor['pds_state']=['enabled'=>true,'sections'=>[],'owner'=>'account:1','csrf_token'=>'offline-preview-token','save_url'=>'pds-save.php','faculty_link'=>null];
    if (in_array('--saved',$argv,true)) {
        $savedNames=array_fill_keys(array_keys(synk_hr_pds_fields('personal')),'');
        $savedNames['surname']='Corrected Surname'; $savedNames['first_name']='Corrected First';
        $profileEditor['pds_state']['sections']['personal']=['values'=>$savedNames,'revision'=>2,'updated_at'=>'2026-11-18 10:00:00'];
        $family=array_fill_keys(array_keys(synk_hr_pds_fields('family')),'');
        $family+=['spouses'=>[],'children'=>[['full_name'=>'Saved Child','date_of_birth'=>'2010-01-02']]];
        $family['children_apply']='Yes'; $family['father_surname']='Saved Parent';
        $profileEditor['pds_state']['sections']['family']=['values'=>$family,'revision'=>1,'updated_at'=>'2026-11-18 10:00:00'];
        $profileEditor['pds_state']['sections']['education']=['values'=>['education'=>[]],'revision'=>2,'updated_at'=>'2026-11-18 10:00:00'];
        $profileEditor['pds_state']['sections']['learning']=['values'=>['trainings'=>[]],'revision'=>1,'updated_at'=>'2026-11-18 10:00:00'];
        $profileEditor['pds_state']['sections']['eligibility']=['values'=>['eligibilities'=>[]],'revision'=>1,'updated_at'=>'2026-11-18 10:00:00'];
        $declarations=array_fill_keys(array_keys(synk_hr_pds_fields('declarations')),'');
        $declarations['q35b']='No'; $declarations['q40a']='Yes'; $declarations['indigenous_group']='Saved group';
        $profileEditor['pds_state']['sections']['declarations']=['values'=>$declarations,'revision'=>3,'updated_at'=>'2026-11-18 10:00:00'];
        $certification=array_fill_keys(array_keys(synk_hr_pds_fields('certification')),''); $certification['declaration_acknowledged']='0';
        $certification['certification_id_number']='000ABC';
        $profileEditor['pds_state']['sections']['certification']=['values'=>$certification,'revision'=>2,'updated_at'=>'2026-11-18 10:00:00',
            'attachments'=>['photo'=>['name'=>'old-pds-photo.png','mime'=>'image/png','size'=>100],
                'signature_page_1'=>['name'=>'<signature>.png','mime'=>'image/png','size'=>100]]];
    }
}
chdir(__DIR__.'/../professor');
ob_start(); require __DIR__.'/../backend/faculty_profile_editor_view.php'; $html = ob_get_clean();
$dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
$checks = 0;
$check = static function (bool $condition,string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message); $checks++;
};
$check($xpath->query('//*[@id="profile-completion"]')->length===0,'The professor top completion panel is removed.');
$check($xpath->query('//*[@role="progressbar"]')->length===1,'Only the sidebar shows a completion indicator.');
$check($xpath->query('//*[@data-profile-tab]')->length===2,'The profile page has only Profile and Personal Data Sheet tabs.');
$check($xpath->query('//*[@data-profile-heading and ancestor::*[contains(@class,"profile-page-heading")]]')->length===2,'Both tab headings occupy the shared page header above navigation.');
$check($xpath->query('//*[@id="faculty-panel-pds"]//h1 | //*[@id="faculty-panel-pds"]//h2[text()="Your Personal Data Sheet"]')->length===0,'The PDS panel does not repeat or reposition the page title.');
$check($xpath->query('//*[@id="faculty-panel-researches"] | //*[@id="faculty-panel-trainings"]')->length===0,'Separate research and training editors are removed from the profile UI.');
$savedPds=in_array('--hr',$argv,true) && in_array('--saved',$argv,true);
$check($xpath->query('//*[@data-pds-list="trainings"]//*[@data-pds-record]')->length===($savedPds?1:2),'Saved HR training lists take precedence over profile suggestions, including an empty list.');
$check($xpath->query('//*[@id="faculty-profile-form"]//*[@name="full_name"]')->length===1,'The existing saved profile form is retained.');
$check($xpath->query('//*[@id="pds-preview"]//form | //*[@id="pds-preview"]//input[@form]')->length===0,'The PDS preview cannot post through a form.');
$check($xpath->query('//*[@id="pds-preview"]/ancestor::form | //*[@id="faculty-profile-form"]//*[@data-pds-control]')->length===0,'PDS entries are outside the existing save payload.');
$check($xpath->query('//*[@id="pds-section-declarations"]//input[@type="radio"]')->length===24,'All twelve declaration answers are available.');
$check($xpath->query('//*[@id="pds-section-declarations"]//input[@checked]')->length===($savedPds?2:0),'Only explicitly saved declaration answers are selected.');
$check($xpath->query('//*[@id="pds-personal-sex_at_birth"]/option[@selected]')->length===0,'Existing gender does not infer sex at birth.');
$check($xpath->query('//*[@id="pds-contact-agency_employee_number" and @value=""]')->length===1,'The faculty number is not silently assigned as the agency employee number.');
$check($xpath->query('//*[@id="pds-contact-email" and @value="faculty@example.edu"]')->length===1,'The PDS contact email starts from the existing account.');
$check($xpath->query('//*[@id="faculty-profile-form"]//*[@id="pds-contact-email"]')->length===0,'Editing the PDS contact address cannot change login identity.');
$check($xpath->query('//*[@id="pds-preview"]//button[not(@type="button")]')->length===0,'Preview controls never submit profile forms.');
if (in_array('--notice',$argv,true)) $check($xpath->query('//*[@data-profile-saved-notice and text()="Your faculty profile has been saved."]')->length===1,'A confirmed profile save triggers the same success feedback.');
if (in_array('--hr',$argv,true)) {
    $check($xpath->query('//*[@data-pds-persistent="1"]')->length===11,'Every PDS section supports independent saving.');
    $check($xpath->query('//*[@data-pds-save]')->length===1,'A section Save button is available.');
    $expectedSurname=in_array('--saved',$argv,true) ? 'Corrected Surname' : 'ANTONIO';
    $check($xpath->query('//*[@id="pds-personal-surname" and @value="'.$expectedSurname.'"]')->length===1,'Saved names take priority over the profile-name suggestion.');
    if (in_array('--saved',$argv,true)) $check($xpath->query('//*[@id="pds-personal-middle_name" and @value=""]')->length===1,'Saved blank middle names stay blank.');
    else $check($xpath->query('//*[@id="pds-personal-first_name" and @value="ELBREN"] | //*[@id="pds-personal-middle_name" and @value="OSORIO"] | //*[@id="pds-personal-name_extension" and @value="JR."]')->length===3,'Given, middle, and extension names prefill correctly.');
    if (in_array('--saved',$argv,true)) {
        $check($xpath->query('//*[@id="pds-family-father_surname" and @value="Saved Parent"] | //*[@id="pds-family-children-0-full_name" and @value="Saved Child"]')->length===2,'Saved parents and child records reload into the form.');
        $check($xpath->query('//*[@data-pds-list="education"]/*[@data-pds-records]/*[@data-pds-record]')->length===0,'An intentionally emptied PDS education list does not resurrect profile degrees.');
        $check($xpath->query('//*[@name="pds-declarations-q35b" and @value="No" and @checked] | //*[@name="pds-declarations-q40a" and @value="Yes" and @checked]')->length===2,'Saved Yes and No answers reload without changing radio option values.');
        $check($xpath->query('//*[@id="pds-certification-certification_id_number" and @value="000ABC"]')->length===1,'Certification identifiers preserve their leading zeros.');
        $check($xpath->query('//*[@id="pds-preview"]//input[@type="file"] | //*[@id="pds-preview"]//*[@data-pds-file-slot]')->length===0,'Signatures and thumbmarks have no digital upload controls.');
        $check(strpos($html,'<signature>')===false && strpos($html,'&lt;signature&gt;.png')===false,'Previously uploaded signatures are not displayed or reused.');
        $check(strpos($html,'old-pds-photo.png')===false,'Previously uploaded PDS photos are not displayed or reused.');
    }
}
$check($xpath->query('//*[@data-pds-file-slot="photo"] | //*[@id="pds-certification-photo"] | //*[@id="pds-certification-photo_taken_date"]')->length===0,'PDS photo uploads and the digital-photo date input are removed.');
$check($xpath->query('//*[@data-pds-print-photo]//img | //*[@data-pds-print-photo]//input')->length===0,'The printed PDS photo area stays blank and accepts no digital input.');
$photoQuery='//*[@data-pds-profile-photo-reference]//img[@src="/synk/assets/img/avatars/1.png"]';
$check($xpath->query($photoQuery)->length===(in_array('--photo',$argv,true)?1:0),'The on-screen photo reference uses the existing profile image only.');
$check($xpath->query('//*[@data-pds-profile-photo-reference]')->length===1,'Photo guidance is available without requiring an uploaded profile image.');
$check($xpath->query('//*[@id="pds-certification-date_accomplished"]')->length===1 && $xpath->query('//*[@id="pds-section-certification"]//*[@data-pds-key and starts-with(@data-pds-key,"signature_date_")]')->length===0,'One PDS date replaces the four independently editable page dates.');
$check($xpath->query('//*[@id="pds-certification-oath_date"] | //*[@id="pds-certification-oath_officer"] | //*[@id="pds-section-certification"]//summary[text()="Oath administration"]')->length===0,'Oath administration is left to the attorney office and is not a faculty input.');
foreach (synk_hr_pds_sections() as $section) {
    $scope='//*[@id="pds-section-'.$section.'"]'; $keys=[]; $files=[];
    foreach ($xpath->query($scope.'//*[@data-pds-control and not(ancestor::*[@data-pds-record])]') as $control) {
        if ($control->getAttribute('type')==='file') $files[]=$control->getAttribute('data-pds-key');
        else $keys[]=$control->getAttribute('data-pds-key');
    }
    $keys=array_values(array_unique($keys)); sort($keys); $expected=array_keys(synk_hr_pds_fields($section)); sort($expected);
    $check($keys===$expected,'UI scalar fields exactly match the supported schema for '.$section.'.');
    $listKeys=[];
    foreach ($xpath->query($scope.'//*[@data-pds-list]') as $list) $listKeys[]=$list->getAttribute('data-pds-list');
    $expectedLists=array_keys(synk_hr_pds_record_lists($section)); sort($listKeys); sort($expectedLists);
    $check($listKeys===$expectedLists,'UI repeatable lists match the schema for '.$section.'.');
    foreach (synk_hr_pds_record_lists($section) as $listKey=>$definition) {
        $keys=[];
        foreach ($xpath->query($scope.'//*[@data-pds-list="'.$listKey.'"]//template//*[@data-pds-control]') as $control) $keys[]=$control->getAttribute('data-pds-key');
        sort($keys); $expected=array_keys($definition['fields']); sort($expected);
        $check($keys===$expected,'Every '.$listKey.' record field can be saved and no UI field is lost.');
    }
    if ($section==='certification') { sort($files); $expected=synk_hr_pds_attachment_slots(); sort($expected); $check($files===$expected,'Every PDS upload slot has private persistence.'); }
}
$ids = [];
foreach ($xpath->query('//*[@id="pds-preview"]//*[@id]') as $element) {
    $id = $element->getAttribute('id');
    if (isset($ids[$id])) throw new RuntimeException('Duplicate ID: '.$id);
    if ($xpath->query('//*[@id="'.$id.'"]')->length!==1) throw new RuntimeException('PDS ID collides with an existing control: '.$id);
    $ids[$id] = true;
}
$check(true,'All generated control IDs are unique.');
foreach ($xpath->query('//*[@id="pds-preview"]//input[not(@type="radio") and not(@type="checkbox")] | //*[@id="pds-preview"]//select | //*[@id="pds-preview"]//textarea') as $control) {
    $check($xpath->query('//label[@for="'.$control->getAttribute('id').'"]')->length===1,'Every PDS control has a label.');
}
require_once __DIR__.'/../backend/faculty_pds_preview_helper.php';
$escapedPrefill = synk_faculty_pds_preview_prefill(['gender'=>'Male','gsis'=>'0001','sss'=>'0002'],[],[],[],'safe@example.edu');
$check(!array_intersect_key($escapedPrefill['values'],array_flip(['sex_at_birth','umid','philsys_pcn'])), 'Different identifiers and gender are never relabeled into PDS fields.');
ob_start(); synk_faculty_pds_preview_record(['singular'=>'degree','fields'=>[['type'=>'text','key'=>'degree','label'=>'Degree']]],'escape-test',['degree'=>'<img src=x onerror=alert(1)>'],$today->format('Y-m-d'),true); $escapedHtml = ob_get_clean();
$check(strpos($escapedHtml,'<img')===false && strpos($escapedHtml,'&lt;img')!==false,'Saved text is escaped in both record summaries and values.');
if (($argv[1] ?? '') === '--render') {
    if (empty($argv[2])) throw new RuntimeException('An output path is required.');
    $html = str_replace('<head>','<head><base href="/synk/professor/">',$html);
    if (file_put_contents($argv[2],$html)===false) throw new RuntimeException('Could not render the offline UI fixture.');
}
echo 'Faculty PDS preview: ',$checks,' checks passed. No database or draft writes.',PHP_EOL;

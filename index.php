<?php
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

loadData();

if (isset($_REQUEST['export'])) {
    die(csv_download());
}

function loadData() {
     

    $projects_file = file_get_contents("projects.json");   
    $GLOBALS['data'] = json_decode($projects_file, true, 1024, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    
}

function checkParticipantID($participant, $id) {
    $uuid = getParticipantId($participant);
    return $id == $uuid;
}

function getParticpant($participants, $id) {
    foreach ($participants as $participant) {
        if (checkParticipantID($participant, $id)) {
            return $participant;
        }
    }
    return NULL;
}

function getParticipantId($participant) {
    $uuidstring = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$participant['name'].$participant['surname']);
    $uuidstring = strtoupper($uuidstring);
    $uuidstring = preg_replace("/[^0-9A-Z]/", "", $uuidstring);
    $uuid = hash("crc32",$uuidstring);
    return $uuid;
}


function getProject($project_title) {
    foreach ($GLOBALS['data']['projects'] as $project) { 
        if ($project['title'] == $project_title) {
            return $project;
        }
    }
    return NULL;
}

function checkQuota($project_name, $group_name = null){
    $project = getProject($project_name);
    if ($project == NULL) {
        echo("Warning : project not found!");
        return false;
    }

    // first check group quota
    $group_quota = 0;
    if (@$project['groups'] != NULL) {
        foreach ($project['groups'] as $group){
            if ($group['name']==$group_name) {
                $group_quota = $group['quota'];
                break;
            }
        }
    
        $count = 0;
        foreach ($GLOBALS['data']['participants'] as $participant) {
            if (@$participant['project'] == $project_name && $participant['group'] == $group_name){
            $count++;    
            }        
        }
        if ($count >= $group_quota) return false;
    }  

    $count = 0;
    foreach ($GLOBALS['data']['participants'] as $participant) {
        if (@$participant['project'] == $project_name) $count++;
    }
    return $count < $project['quota'];
}

function validateSubscription($project, $id, $comment){

    foreach ($GLOBALS['data']['participants'] as &$participant) {
        if (checkParticipantID($participant, $id)) {
            $participant['project'] = $project;
            $participant['comment'] = $comment;
            
            break;
        }
    }

    $json = json_encode($GLOBALS['data'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    
    $f = fopen('projects.json', 'w');
    flock($f, LOCK_EX);
    fwrite($f, $json);
    flock($f, LOCK_UN);
    fclose($f);

    $name = $participant['name'];
    $surname = $participant['surname'];
    $group = $participant['group'];

    echo('<div class="center">');

    $validation = $GLOBALS['data']['settings']['validationString'];

    $patterns = ['/\$name/', '/\$surname/', '/\$group/', '/\$project/'];
    $replacements = ["$name", "$surname", "$group", "$project"];


    echo(preg_replace($patterns, $replacements, $validation));

    if (@$participant['comment'] !=NULL) {
        $comment = $participant['comment'];
        echo("<p>Vous avez laissé ce commentaire: <blockquote>$comment</blockquote></p>");
    }

    echo('Vous pouvez modifier votre choix en retournant sur <a href="index.php?id='.$id.'">la page du formulaire</a>.');
    echo('<br><br>');
    echo('</div>');
}

function showList() {
    echo('<div class="center">');
    echo('<br><br><p><b>Inscriptions validées</b></p>');
    $count = 0;
    $participants = $GLOBALS['data']['participants'];
    usort($GLOBALS['data']['participants'], "shortByProjects");
    echo("<ul>\n");
    $last_project = null;

    foreach ($GLOBALS['data']['participants'] as $participant) {
        $name = $participant['name'];
        $surname = $participant['surname'];
        $group = $participant['group'];
        $project = $participant['project'] ?? null;
        if ($last_project != $project) {
            $last_project = $project;
            echo("</ul>\n$project\n<ul>\n");
        }
        if ($project != null) echo("<li>$name $surname ($group)</li>\n");
        
    }
    echo("</ul>\n");
    echo("<br><br><p><b>En attente d'inscription</b></p>\n<ul>");
    foreach ($GLOBALS['data']['participants'] as $participant) {
        $name = $participant['name'];
        $surname = $participant['surname'];
        $group = $participant['group'];
        $project = $participant['project'] ?? null;
        if ($project == null) echo("<li>$name $surname ($group)</li>\n");
    }
    echo("</ul>\n"); 
    echo('</div>'); 
}

function showMailingList() {
    
    usort($GLOBALS['data']['participants'], "shortByGroups");
   
    $last_group = $GLOBALS['data']['participants'][0]['group'];

    echo("<table class='mailinglist'");
    echo("<tr><th colspan='6'><b>$last_group</b></th></tr>");

    
    foreach ($GLOBALS['data']['participants'] as $participant) {

        $id = getParticipantId($participant);
        $linkid = "<a href='?id=$id'>$id</a>";

        $mail = $participant['mail'];
        $name = $participant['name'];
        $surname = $participant['surname'];
        $group = $participant['group'];
        $comment = $participant['comment'] ?? "";
        $project = $participant['project']  ?? "<span class='red'>Non inscrit.e</span>";
        $url = $_SERVER['HTTP_HOST'].$_SERVER["PHP_SELF"]."?id=$id";
        $message = "Bonjour $name $surname. %0D%0A%0D%0A Afin de vous inscrire à un pôle pour le premier semestre, merci de faire votre choix en validant le formulaire à cette adresse: %0D%0A %0D%0A $url%0D%0A%0D%0AVotre code de particpation est le $id. Merci de le conserver.%0D%0A%0D%0A Attention, ce lien et ce code vous sont personnel, ne les partagez avec personne.%0D%0A%0D%0A";
        if ($group != $last_group) {
            echo("</table><table class='mailinglist'");
            $last_group = $group;
            echo("<tr><th colspan='6'><b>$group</b></th></tr>");
        }
        $mailto = "<a href='mailto:$mail?subject=Inscription pôle semestre 1&body=$message'>Inviter</a>";
        echo("<tr><td>$name</td><td>$surname</td><td>$project</td><td>$comment</td><td>$linkid</td><td>$mailto</td></tr>");
    }
    echo("</table>");
    echo('<br><br><p>Exporter <a href="?export">la liste</a>.</p>');
}

function shortByGroups($a, $b){
    return strcmp($a["group"], $b["group"]);
}

function shortByProjects($a, $b){
    return @strcmp(@$a["project"], @$b["project"]);
}

function showParticipantForm($participant) {
    $name = $participant['name'];
    $surname = $participant['surname'];
    $group = $participant['group'];
    $project = @$participant["project"];

    $welcome = $GLOBALS['data']['settings']['welcomeString'];
    $subscription = $GLOBALS['data']['settings']['subscriptionString'];
    
    echo('<div class="center">');

    $patterns = ['/\$name/', '/\$surname/', '/\$group/','/\$project/'];
    $replacements = ["$name", "$surname", "$group", "$project"];

    echo(preg_replace($patterns, $replacements, $welcome));

    
    if ($project != null) echo(preg_replace($patterns, $replacements, $subscription));
    

    echo("<form id='pole-form' method='post'>\n");
    
    $projects = $GLOBALS['data']['projects'];

    shuffle($projects);
    
    foreach ($projects as $project) {
        $disabled = "";
        $alert = "";
        if (!checkQuota($project['title'], $participant["group"])){
            $disabled = 'disabled';
            $alert = 'title="Choix insidponible."';// onclick="alert(\'Choix indisponible.\')"';
        }

        $title = $project['title'];
        
        echo("<label class='container $disabled' for='$title' $alert>$title
        <input type='radio' id='$title' name='project' value='$title' required $disabled>
        <span class='checkmark'></span>
        </label>");
    }
    echo("<input type='text' class='comment' id='comment' name='comment' value='' placeholder='Commentaire optionnel'>");

    $participant_id = getParticipantId($participant);
    echo("<input type='hidden' name='id' value='$participant_id')>\n");
    echo("<input type='submit' name='validate' id='pole-submit' value='Valider'>\n");  
    echo("</form>\n");

    echo("<br><br><p><i>Si vous n'êtes pas $name $surname, merci de <a href='index.php'>vous indentifier avec votre code de particpation</a>.</i></p>");
    echo('</div>');
}

function showLogin() {
    echo('<div class="center">');
    echo("<p>Veuillez vous identifier avec votre code de participation.</p>");
    echo("<form id='pole-form' method='get'>\n");
    echo("<input type='text' class='login' id='id' name='id' value='' required>");
    echo("<input type='submit' id='pole-submit' value='Valider'>\n");  
    echo("</form>\n");
    echo('</div>');
}

function csv_download() {
    loadData();
    $participants = $GLOBALS['data']['participants'];
    usort($participants, "shortByProjects");
    //array_unshift($participants , ['groupe', 'mèl','nom', 'prénom',  'projet']);

    $filename = "export.csv";
    $delimiter=";";
    // open raw memory as file so no temp files needed, you might run out of memory though
    $f = fopen('php://memory', 'w'); 
    // loop over the input array
    fputcsv($f, ['prénom', 'nom', 'mèl', 'groupe', 'projet', 'commentaire'], $delimiter); 
    foreach ($participants as $line) { 
        // generate csv lines from the inner arrays
        $sorted_line= [$line['name'], $line['surname'], $line['mail'], $line['group'], @$line['project']??"", @$line['comment']??""];
        fputcsv($f, $sorted_line, $delimiter); 
    }
    // reset the file pointer to the start of the file
    fseek($f, 0);
    // tell the browser it's going to be a csv file
    header('Content-Type: application/csv');
    // tell the browser we want to save it instead of displaying it
    header('Content-Disposition: attachment; filename="'.$filename.'";');
    // make php send the generated csv lines to the browser
    fpassthru($f);
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="utf-8">
    <meta content="text/html; charset=utf-8">
	<title>Inscription Pôle 1er Semestre</title>
    <link rel="stylesheet" href="styles.css">
<head>
<body>
<h3 class='center'><?php echo($GLOBALS['data']['settings']['headerString'])?></h3><br>
<?php
    if (isset($_REQUEST['validate']) && isset($_REQUEST['id']) && isset($_REQUEST['project'])) { // validate choice
        validateSubscription($_REQUEST['project'], $_REQUEST['id'], $_REQUEST['comment']);
        echo("<p class='center'>Consultez <a href='?list'>les inscriptions actuelles</a>.</p>");
    } else if (isset($_REQUEST['id'])) {
        $participant_id = $_REQUEST['id'];
        $current_participant = null;
        $participants = $GLOBALS['data']['participants'];

        $current_participant = getParticpant($participants, $participant_id);

        if ($current_participant == NULL) {
            echo("<h3 class='center'>Oooops ! ce code de participation est invalide.</h3>");
            showLogin();
        } else {
            showParticipantForm($current_participant);
            echo("<p class='center'>Consultez <a href='?list'>les inscriptions actuelles</a>.</p>");
        }
    } else if (isset($_REQUEST['mailinglist'])) {
        showMailingList();
        echo("<p class='center'>Consultez <a href='?list'>les inscriptions actuelles</a>.</p>");

    } else if (isset($_REQUEST['list'])) {
        showList();
    } else {
        showLogin();
    }
?>

</body>
</html>
<?php

##
# © 2015 Partners HealthCare System, Inc. All Rights Reserved. 
##

##
#include redcap plugin functions 
##
define("REDCAP_WEBROOT", "/var/www/html/redcap/");
require_once REDCAP_WEBROOT . "redcap_connect.php";

##
#Must have PHP extention "mbstring" installed in order to render UTF-8 characters 
#properly AND also the PDF unicode fonts installed
##
$pathToPdfUtf8Fonts = APP_PATH_WEBTOOLS . "pdf" . DS . "font" . DS . "unifont" . DS;
if (function_exists('mb_convert_encoding') && is_dir($pathToPdfUtf8Fonts)) {
    #Define the UTF-8 PDF fonts' path
    define("FPDF_FONTPATH", APP_PATH_WEBTOOLS . "pdf" . DS . "font" . DS);
    define("_SYSTEM_TTFONTS", APP_PATH_WEBTOOLS . "pdf" . DS . "font" . DS);
    #Set contant
    define("USE_UTF8", true);
    #Use tFPDF class for UTF-8 by default
    require_once APP_PATH_CLASSES . "tFPDF.php";
} else {
    #Set contant
    define("USE_UTF8", false);
    #Use normal FPDF class
    require_once APP_PATH_CLASSES . "FPDF.php";
}
##
#If using language "Japanese", then use MBFPDF class for multi-byte string rendering
##
if ($project_language == 'Japanese') {
    require_once APP_PATH_CLASSES . "MBFPDF.php"; #Japanese
    #Make sure mbstring is installed
    if (USE_UTF8) {
        exit("ERROR: In order for the Japanese text to render correctly in the PDF, you must have the PHP extention \"mbstring\" installed on your web server.");
    }
}

##
#Include other files needed
##
require_once APP_PATH_DOCROOT . 'ProjectGeneral/form_renderer_functions.php';
require_once APP_PATH_DOCROOT . "PDF/functions.php"; #This MUST be included AFTER we include the FPDF class

global $table_pk;
$project_id = $_GET['pid'];
$ref_parts = explode("?", $_SERVER['HTTP_REFERER']);
$ref_parts = explode("&", $ref_parts[1]);
foreach ($ref_parts as $param) {
    $parts = explode("=", $param);
    $params[$parts[0]] = $parts[1];
}
$form_name = $params['page'];
$event_id = $params['event_id'];
$event_parts=explode('_',$_GET['event']);
if($event_parts[count($event_parts)-2]=="arm"){
    $arm=$event_parts[count($event_parts)-1];
    $app_title.="\nArm $arm";
}

##
#Save fields into metadata array
##
if (isset($_GET['page']) || !empty($form_name)) {
    if (isset($_GET['page'])) {
        $Query = "select * from redcap_metadata where project_id = $project_id and ((form_name = '{$_GET['page']}'
			  and field_name != concat(form_name,'_complete')) or field_name = '$table_pk') order by field_order";
    } elseif (!empty($form_name)) {
        $Query = "select * from redcap_metadata where project_id = $project_id and ((form_name = '{$form_name}'
			  and field_name != concat(form_name,'_complete')) or field_name = '$table_pk') order by field_order";
    }
} else {
    $Query = "select * from redcap_metadata where project_id = $project_id and
    			  (field_name != concat(form_name,'_complete') or field_name = '$table_pk') order by field_order";
}
$QQuery = mysql_query($Query);
$metadata = array();
$field_names = array();
while ($row = mysql_fetch_assoc($QQuery)) {
    #If user doesn't have rights to view a form, then don't display it in the PDF
    if (!isset($user_rights['forms'][$row['form_name']]) || $user_rights['forms'][$row['form_name']] == '0') {
        continue;
    }
    #If field is an "sql" field type, then retrieve enum from query result
    if ($row['element_type'] == "sql") {
        $row['element_enum'] = getSqlFieldEnum($row['element_enum']);
    }
    #If PK field...
    if ($row['field_name'] == $table_pk) {
        // Ensure PK field is a text field
        $row['element_type'] = 'text';
    }
    #Store metadata in array
    $metadata[] = $row;
    #also save the fieldnames seperately
    array_unshift($field_names, "'" . $row['field_name'] . "'");
}
$field_names = implode(",", $field_names);
#Create array of all checkbox fields with "0" defaults
$chkbox_fields = getCheckboxFields(true);

##
#Save field data into data array 
#
$Data = array();
#GET SINGLE RECORD'S DATA (ALL FORMS/ALL EVENTS)
if (isset($_GET['record']) && !isset($_GET['event'])) {
    #If in DAG, only give DAG's data
    if ($user_rights['group_id'] == "") {
        $group_sql = "";
    } else {
        $group_sql = "AND record IN (" . pre_query("SELECT record FROM redcap_data where record = '" . prep($_GET['record']) . "' and project_id = $project_id and field_name = '__GROUPID__' AND value = '" . $user_rights['group_id'] . "'") . ")";
    }
    $data_sql = "SELECT record, event_id, field_name, value FROM redcap_data 
					where project_id = $project_id and record = '" . prep($_GET['record']) . "' 
					$group_sql and field_name in ('$table_pk', {$field_names})
					ORDER BY abs(record), record, event_id";
    $dQuery = mysql_query($data_sql);
    while ($row = mysql_fetch_assoc($dQuery)) {
        $row['record'] = trim($row['record']);
        if (isset($chkbox_fields[$row['field_name']])) {
            #Checkboxes
            #First set default values if not set yet
            if (!isset($Data[$row['record']][$row['event_id']][$row['field_name']])) {
                $Data[$row['record']][$row['event_id']][$row['field_name']] = $chkbox_fields[$row['field_name']];
            }
            #Now set this value
            $Data[$row['record']][$row['event_id']][$row['field_name']][$row['value']] = "1";
        } else {
            #Regular non-checkbox fields
            $Data[$row['record']][$row['event_id']][$row['field_name']] = $row['value'];
        }
    }
    mysql_free_result($dQuery);
}
#GET SINGLE RECORD'S DATA (SINGLE FORM ONLY)
elseif (isset($_GET['record']) && isset($_GET['event'])) {
    $id = trim($_GET['record']);
    if ($double_data_entry && $user_rights['double_data'] > 0) {
        $id .= "--" . $user_rights['double_data'];
    }
    $data_sql = "select field_name, value from redcap_data where project_id = $project_id 
				and event_id = {$event_id} and record = '" . prep($id) . "' and value != '' 
				and field_name in ('$table_pk', {$field_names})";
    $dQuery = mysql_query($data_sql);
    while ($row = mysql_fetch_assoc($dQuery)) {
        if (isset($chkbox_fields[$row['field_name']])) {
            #Checkboxes
            #First set default values if not set yet
            if (!isset($Data[$id][$event_id][$row['field_name']])) {
                $Data[$id][$event_id][$row['field_name']] = $chkbox_fields[$row['field_name']];
            }
            #Now set this value
            $Data[$id][$event_id][$row['field_name']][$row['value']] = "1";
        } else {
            #Regular non-checkbox fields
            $Data[$id][$event_id][$row['field_name']] = $row['value'];
        }
    }
    mysql_free_result($dQuery);
} else {
    $Data[''][''] = null;
}

#If form was downloaded from Shared Library and has an Acknowledgement, render it here
$acknowledgement = getAcknowledgement($project_id, $form_name);

##
#Manipulate the data structures in order to get the PDF to only show the answers 
#that were selected.
##
foreach ($Data as $d_key_0 => $d_value_0) {
    foreach ($Data[$d_key_0] as $d_key_1 => $d_value_1) {
        foreach ($Data[$d_key_0][$d_key_1] as $key => $value) {
            foreach ($metadata as $m_key => $m_value) {
                if ($metadata[$m_key]['form_name'] != $form_name) {
                    $metadata[$m_key]['form_name'] = $form_name;
                }
                #If question has no data then remove it from the metadata. 
                #This will cause the question to be skipped completely in the PDF. 
                if ($Data[$d_key_0][$d_key_1][$metadata[$m_key]['field_name']] == NULL) {
                    unset($metadata[$m_key]);
                } elseif ($metadata[$m_key]['field_name'] == $key) {
                    if (($metadata[$m_key]['element_type'] == 'radio' && empty($metadata[$m_key]['grid_name'])) || $metadata[$m_key]['element_type'] == 'select') {
                        $choices = $metadata[$m_key]['element_enum'];
                        $choices = explode('\n', $choices);
                        $selected = array();
                        foreach ($choices as $choice) {
                            $parts = explode(",", $choice);
                            $selected[trim($parts[0])] = $parts[1];
                        }
                        $keep = "{$value}," . $selected["{$value}"];
                        $metadata[$m_key]['element_enum'] = $keep;
                    }
                    if ($metadata[$m_key]['element_type'] == 'yesno') {
                        $metadata[$m_key]['element_type'] = 'select';
                        if ($value == 1) {
                            $metadata[$m_key]['element_enum'] = '1, Yes';
                        } elseif ($value == 0) {
                            $metadata[$m_key]['element_enum'] = '0, No';
                        } else {
                            unset($metadata[$m_key]);
                        }
                    }
                    if ($metadata[$m_key]['element_type'] == 'truefalse') {
                        $metadata[$m_key]['element_type'] = 'select';
                        if ($value == 1) {
                            $metadata[$m_key]['element_enum'] = '1, True';
                        } elseif ($value == 0) {
                            $metadata[$m_key]['element_enum'] = '0, False';
                        } else {
                            unset($metadata[$m_key]);
                        }
                    }
                    if ($metadata[$m_key]['element_type'] == 'checkbox') {
                        $choices = $metadata[$m_key]['element_enum'];
                        $choices = explode('\n', $choices);
                        $choice_array = array();
                        foreach ($choices as $choice) {
                            $parts = explode(",", $choice);
                            $choice_array[trim($parts[0])] = $parts[1];
                        }
                        $keep = "";
                        foreach ($value as $k => $v) {
                            if ($v == 1) {
                                $keep = $keep . "{$k}," . $choice_array["{$k}"] . ' \n';
                            }
                        }
                        $keep = substr($keep, 0, count($keep) - 3); #strip off trailing '\n'
                        $metadata[$m_key]['element_enum'] = $keep;
                    }
                    if ($metadata[$m_key]['element_type'] == 'file') {
                        $metadata[$m_key]['element_type'] = 'text';
                        $metadata[$m_key]['element_note'] = '';
                        $Data[$d_key_0][$d_key_1][$metadata[$m_key]['field_name']] = get_file_name($Data[$d_key_0][$d_key_1][$metadata[$m_key]['field_name']]);
                    }
                }
            }
        }
    }
}
##
#Render the PDF
##
###renderPDF($metadata, $acknowledgement, strip_tags(label_decode($app_title)), $user_rights['data_export_tool'], $Data);
if(substr($redcap_version,0,1)<6) {
        renderPDF($metadata, $acknowledgement, strip_tags(label_decode($app_title)), $user_rights['data_export_tool'], $Data);
}
else {
	// REDCap 6.0.x handles this funciton much differently than 6.5.x - if it's a plugin it simply returns the data in 6.5.x. 6.0.x gives the file as a download
        if ( substr($redcap_version,0,1) == 6 && substr($redcap_version,2,1) == 0) {
		// handle 6.0.x
		renderPDF($metadata, $acknowledgement, strip_tags(label_decode($app_title)), $Data);
	}
	else {
		// handle 6.5.x
		$filename = "values_only_pid_".$project_id;
		// Add timestamp if data in PDF
		$filename .= date("_Y-m-d_Hi");

		header('Content-Type: application/x-download');
		header('Content-Disposition: attachment; filename="'.$filename.'.pdf"');
		header('Cache-Control: private, max-age=0, must-revalidate');
		header('Pragma: public');
		echo renderPDF($metadata, $acknowledgement, strip_tags(label_decode($app_title)), $Data);
	}
}

##
#Utility function to get the document name for a file field
##

function get_file_name($file_index) {
    $file_name_sql = "select doc_name from redcap_edocs_metadata where doc_id={$file_index}";
    $result = mysql_query($file_name_sql);
    $row = mysql_fetch_assoc($result);
    return $row['doc_name'];
}

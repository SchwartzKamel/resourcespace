<?php

use SimpleSAML\Console\Application;

include_once __DIR__ . '/../../../include/db.php';
include __DIR__ . '/../../../include/authenticate.php';
include_once __DIR__ . '/../include/propose_changes_functions.php';

$ref=getval("ref","",true);
# Fetch search details (for next/back browsing and forwarding of search params)
$search=getval("search","");
$order_by=getval("order_by","relevance");
$offset=getval("offset",0,true);
$restypes=getval("restypes","");
if (strpos($search,"!")!==false) {$restypes="";}
$default_sort_direction="DESC";
if (substr($order_by,0,5)=="field"){$default_sort_direction="ASC";}
$sort=getval("sort",$default_sort_direction);
$archive=getval("archive",0,true);
$modal=(getval("modal","")=="true");

$errors=array(); # The results of the save operation (e.g. required field messages)
$editaccess=get_edit_access($ref);

if(isset($propose_changes_always_allow))
    {
    if(!$propose_changes_always_allow)
        {
        # Check user has permission.
        $parameters=array("i",$ref, "i",$userref);
        $proposeallowed=ps_value("SELECT r.ref value from resource r 
                left join collection_resource cr on r.ref=? and cr.resource=r.ref 
                left join user_collection uc on uc.user=? and uc.collection=cr.collection 
                left join collection c on c.ref=uc.collection where c.propose_changes=1", $parameters, "");
        if($proposeallowed=="" && $propose_changes_allow_open)
            {
            include_once '../../../include/search_do.php';
            $proposeallowed=(get_resource_access($ref)==0)?$ref:"";
            }
        }
    }
else
    {
    $error=$lang["error-plugin-not-activated"];
    header("Location:" . $baseurl . "/pages/user/user_messages.php");
    exit();
    }

if(!$propose_changes_always_allow && $proposeallowed=="" && !$editaccess)
    {
    # The user is not allowed to edit this resource or the resource doesn't exist.
    $error=$lang['error-permissiondenied'];
    header("Location:" . $baseurl . "/pages/user/user_messages.php");
    exit();
    }

if($editaccess)
    {
    $view_user = getval("proposeuser",0);

    if(getval("resetform","") != "" && enforcePostRequest(false))
        {
        delete_proposed_changes($ref, $view_user);
        }

    $userproposals = ps_query("SELECT pc.user, u.username from propose_changes_data pc 
            left join user u on u.ref=pc.user where resource=? 
            group by pc.user order by u.username asc", array("i",$ref));
    if(!in_array($view_user,array_column($userproposals,"user")) && count($userproposals) > 0)
        {
        $view_user = $userproposals[0]["user"];
        }
    $proposed_changes=get_proposed_changes($ref, $view_user);
    }
else
    {
     if(getval("resetform","") != "" && enforcePostRequest(false))
        {
        delete_proposed_changes($ref, $userref);
        }
    $proposed_changes=get_proposed_changes($ref, $userref);
    }

# Fetch resource data.
$resource=get_resource_data($ref);

# Load resource data
$proposefields=get_resource_field_data($ref,false,true);

// Save data
if(
    (
        getval("save", "") != ""
        || (getval("submitted", "") != "" && getval("resetform", "") == "")
    )
    && enforcePostRequest(false)
    )
    {
    if($editaccess)
        {
        // Set a list of the fields we actually want to change - otherwise any fields we don't submit will get wiped
        $acceptedfields=array();
        foreach($proposed_changes as $proposed_change)
            {
            if(getval("accept_change_" . $proposed_change["resource_type_field"],"")=="on" && !getval("delete_change_" . $proposed_change["resource_type_field"],"")=="on")
                {
                $acceptedfields[]=$proposed_change["resource_type_field"];
                }
            }
        $proposed_changes_fields = array_column($proposed_changes,"resource_type_field");
        // Actually save the data
        save_resource_data($ref,false,$acceptedfields);
        daily_stat("Resource edit",$ref);
        
        // send email to change  proposer with link
        $acceptedchanges=array();
        $acceptedchangescount=0;
        $deletedchanges=array();
        $deletedchangescount=0;
        
        $proposefields = get_resource_field_data($ref, false, true);
        for ($n=0;$n<count($proposefields);$n++)
            {
            node_field_options_override($proposefields[$n]);

            # Has this field been accepted?
            if (getval("accept_change_" . $proposefields[$n]["ref"],"")!="" && in_array($proposefields[$n]["ref"],$proposed_changes_fields))
                {
                debug("propose_changes - accepted proposed change for field " . $proposefields[$n]["title"]);
                $acceptedchanges[$acceptedchangescount]["field"]=$proposefields[$n]["title"];
                $acceptedchanges[$acceptedchangescount]["value"]=$proposefields[$n]["value"];
                $acceptedchangescount++;

                // remove this from the list of proposed changes
                $parameters=array("i",$view_user, "i",$proposefields[$n]['ref'], "i",$ref);
                ps_query("DELETE FROM propose_changes_data 
                    WHERE user = ? AND resource_type_field = ? AND resource = ?", $parameters);
                }

            # Has this field been deleted?
            if (getval("delete_change_" . $proposefields[$n]["ref"],"")!="" && in_array($proposefields[$n]["ref"],$proposed_changes_fields))
                {
                debug("propose_changes - deleted proposed change for field " . $proposefields[$n]["title"]);
                foreach($proposed_changes as $proposed_change)
                    {
                    if($proposed_change["resource_type_field"]==$proposefields[$n]["ref"])
                        {
                        $deletedchanges[$deletedchangescount]["field"]=$proposefields[$n]["title"];
                        $deletedchanges[$deletedchangescount]["value"]=htmlspecialchars($proposed_change["value"]);
                        $deletedchangescount++;
                        }
                    }

                // remove this from the list of proposed changes
                $parameters=array("i",$view_user, "i",$proposefields[$n]['ref'], "i",$ref);
                ps_query("DELETE FROM propose_changes_data 
                    WHERE user = ? AND resource_type_field = ? AND resource = ?", $parameters);
                }
            }

        $templatevars['ref'] = $ref;
        $message = new ResourceSpaceUserNotification;
        $message->set_text("lang_propose_changes_proposed_changes_reviewed");
        $message->append_text($templatevars['ref'] . "<br/>");

        $changesummary = new ResourceSpaceUserNotification;
        $changesummary->set_text("lang_propose_changes_summary_changes");
        $changesummary->append_text("<br/><br/>");
        if($acceptedchangescount>0)
            {
            $changesummary->append_text("lang_propose_changes_proposed_changes_accepted");
            $changesummary->append_text("<br/>");
            }
        for($n=0;$n<$acceptedchangescount;$n++)
            {
            $changesummary->append_text($acceptedchanges[$n]["field"] . " : " . $acceptedchanges[$n]["value"] . "<br/>");
            }
        if($deletedchangescount>0)
            {
            $changesummary->append_text("<br/>");
            $changesummary->append_text("lang_propose_changes_proposed_changes_rejected");
            $changesummary->append_text("<br/><br/>");
            }
        for($n=0;$n<$deletedchangescount;$n++)
            {
            $changesummary->append_text($deletedchanges[$n]["field"] . " : " . htmlspecialchars($deletedchanges[$n]["value"]) . "<br/>");
            }

        $templatevars['changesummary']=$changesummary->get_text();
        $templatevars['url'] = generateurl($baseurl . "/pages/view.php",["ref"=> $ref]); 

        $message->append_text_multi($changesummary->get_text(true));
        $message->set_subject("lang_propose_changes_proposed_changes_reviewed");
        $message->url = $templatevars['url'];
        $message->template = "propose_changes_emailreviewed";
        $message->templatevars = $templatevars;

        send_user_notification([$view_user],$message);
        if(!$modal)
            {
            redirect($baseurl_short."pages/view.php?ref=" . $ref . "&search=" . urlencode($search) . "&offset=" . $offset . "&order_by=" . $order_by . "&sort=".$sort."&archive=" . $archive . "&refreshcollectionframe=true");
            exit();
            }
        else
            {
            $resulttext=$lang["changessaved"];
            }
        }
	else
        {
        // No edit access, save the proposed changes
        $save_errors=save_proposed_changes($ref);
        $submittedchanges=array();
        $submittedchangescount=0;
        if ($save_errors===true)
            {
            $proposed_changes=get_proposed_changes($ref, $userref);
            for ($n=0;$n<count($proposefields);$n++)
                {
                # Has a change to this field been proposed?
                foreach($proposed_changes as $proposed_change)
                    {
                    if($proposed_change['resource_type_field'] != $proposefields[$n]['ref'])
                        {
                        continue;
                        }

                    $proposed_change_value = $proposed_change['value'];
                    if(in_array($proposed_change['type'], $FIXED_LIST_FIELD_TYPES) && '' != $proposed_change_value)
                        {
                        $field_node_options    = extract_node_options(get_nodes($proposefields[$n]['ref'], null, true));
                        $proposed_change_value = array();

                        foreach(explode(', ', $proposed_change['value']) as $proposed_change_node_id)
                            {
                            if('' == $proposed_change_node_id)
                                {
                                continue;
                                }

                            $proposed_change_value[] = $field_node_options[$proposed_change_node_id];
                            }

                        if(is_array($proposed_change_value) && 0 < count($proposed_change_value))
                            {
                            $proposed_change_value = implode(', ', $proposed_change_value);
                            }
                        }
                    $submittedchanges[$submittedchangescount]["field"] = $proposefields[$n]["title"];
                    $submittedchanges[$submittedchangescount]["value"] = htmlspecialchars($proposed_change_value);
                    $submittedchangescount++;
                    }
                }

            // send email to admin/resource owner with link
    
            $changesummary = new ResourceSpaceUserNotification;
            $changesummary->set_text("lang_propose_changes_summary_changes");
            $changesummary->append_text("<br/><br/>");
            for($n=0;$n<$submittedchangescount;$n++)
                {
                $changesummary->append_text($submittedchanges[$n]["field"] . " : " . htmlspecialchars($submittedchanges[$n]["value"]) . "<br/>");
                }

            $templatevars['proposer']=(($username=="") ? $username : $userfullname);
            $templatevars['url'] = generateurl($baseurl . "/plugins/propose_changes/pages/propose_changes.php",["ref"=> $ref,"proposeuser" => $userref]); 

            $message = new ResourceSpaceUserNotification;
            $message->set_text("lang_propose_changes_proposed_changes_submitted");
            $message->append_text("<br/><br/>");
            $message->append_text($templatevars['proposer']);
            $message->append_text("lang_propose_changes_proposed_changes_submitted_text");
            $message->append_text($ref . "<br/><br/>");
            $message->append_text_multi($changesummary->get_text(true));
            $message->set_subject("lang_propose_changes_proposed_changes_submitted");
            $message->url = $templatevars["url"];
            $message->template = "propose_changes_emailproposedchanges";
            $message->templatevars = $templatevars;
            if($propose_changes_notify_admin)
                {
                debug("propose_changes: sending notifications to admins");
                $resource_admins = get_notification_users("RESOURCE_ADMIN");
                send_user_notification($resource_admins,$message);
                }
            if($propose_changes_notify_contributor)
                {
                $notify_user=get_user($resource["created_by"]);
                if($notify_user)
                    {
                    debug("propose_changes: sending notification to resource contributor, " . $notify_user['username'] . ", user id#" . $notify_user['ref'] . " (" . $notify_user['email'] . ")");
                    send_user_notification([$notify_user],$message);
                    }
                }
            $resulttext=$lang["propose_changes_proposed_changes_submitted"];
            }
        }
    }

function propose_changes_is_field_displayed($field)
    {
    global $ref, $resource, $editaccess;
    return !(
        ($field['active']==0)
        ||
        # Field is an archive only field
        ($resource["archive"]==0 && $field["resource_type"]==999)
        # User has no read access
        || !((checkperm("f*") || checkperm("f" . $field["ref"])) && !checkperm("f-" . $field["ref"]) )
        # User has edit access to resource but not to this field
        || ($editaccess && checkperm("F*") && checkperm("F-" . $field["ref"]))
        );
    }

# Allows language alternatives to be entered for free text metadata fields.
function propose_changes_display_multilingual_text_field($n, $field, $translations)
    {
    global $language, $languages, $lang;
    ?>
    <p><a href="#" class="OptionToggle" onClick="l=document.getElementById('LanguageEntry_<?php echo $n?>');if (l.style.display=='block') {l.style.display='none';this.innerHTML='<?php echo $lang["showtranslations"]?>';} else {l.style.display='block';this.innerHTML='<?php echo $lang["hidetranslations"]?>';} return false;"><?php echo $lang["showtranslations"]?></a></p>
    <table class="OptionTable" style="display:none;" id="LanguageEntry_<?php echo $n?>">
    <?php
    reset($languages);
    foreach ($languages as $langkey => $langname)
        {
        if ($language!=$langkey)
            {
            if (array_key_exists($langkey,$translations)) {$transval=$translations[$langkey];} else {$transval="";}
            ?>
            <tr>
            <td nowrap valign="top"><?php echo htmlspecialchars($langname)?>&nbsp;&nbsp;</td>

            <?php
            if ($field["type"]==0)
                {
                ?>
                <td><input type="text" class="stdwidth" name="multilingual_<?php echo $n?>_<?php echo $langkey?>" value="<?php echo htmlspecialchars($transval)?>"></td>
                <?php
                }
            else
                {
                ?>
                <td><textarea rows=6 cols=50 name="multilingual_<?php echo $n?>_<?php echo $langkey?>"><?php echo htmlspecialchars($transval)?></textarea></td>
                <?php
                }
            ?>
            </tr>
            <?php
            }
        }
    ?></table><?php
    }

function propose_changes_display_field($n, $field)
    {
    global $ref, $original_fields, $multilingual_text_fields,
    $is_template, $language, $lang,  $errors, $proposed_changes, $editaccess,
    $FIXED_LIST_FIELD_TYPES,$range_separator, $edit_autosave;

    $edit_autosave=false;
    $name="field_" . $field["ref"];
    $value=$field["value"];
    $value=trim($value??"");
    $proposed_value="";            
    # is there a proposed value set for this field?
    foreach($proposed_changes as $proposed_change)
        {
        if($proposed_change['resource_type_field'] == $field['ref'])
            {
            $proposed_value = $proposed_change['value'];
            }
        }

    // Don't show this if user is an admin viewing proposed changes, needs to be on form so that form is still submitted with all data
    if ($editaccess && $proposed_value=="")
        {
        ?>
        <div style="display:none" >
        <?php
        }

    if ($multilingual_text_fields)
        {
        # Multilingual text fields - find all translations and display the translation for the current language.
        $translations=i18n_get_translations($value);
        if (array_key_exists($language,$translations)) {$value=$translations[$language];} else {$value="";}
        }

    ?>
    <div class="Question ProposeChangesQuestion" id="question_<?php echo $n?>">
    <div class="Label ProposeChangesLabel" ><?php echo htmlspecialchars($field["title"])?></div>

    <?php 
    # Define some Javascript for help actions (applies to all fields)
    $help_js="onBlur=\"HideHelp(" . $field["ref"] . ");return false;\" onFocus=\"ShowHelp(" . $field["ref"] . ");return false;\"";

    #hook to modify field type in special case. Returning zero (to get a standard text box) doesn't work, so return 1 for type 0, 2 for type 1, etc.
    $modified_field_type="";
    $modified_field_type=(hook("modifyfieldtype"));
    if ($modified_field_type){$field["type"]=$modified_field_type-1;}

    hook("addfieldextras");

    // ------------------------------
    // Show existing value so can edit
    $value=preg_replace("/^,/","",$field["value"]??"");
    $realvalue = $value; // Store this in case it gets changed by view processing
    if ($value!="")
            {
            # Draw this field normally.			
            ?><div class="propose_changes_current ProposeChangesCurrent"><?php display_field_data($field,true); ?></div><?php
            }                        
        else
            {
            ?><div class="propose_changes_current ProposeChangesCurrent"><?php echo $lang["propose_changes_novalue"] ?></div>    
            <?php
            }
        if(!$editaccess && $proposed_value=="")
            {
            ?>
            <div class="propose_change_button" id="propose_change_button_<?php echo $field["ref"] ?>">
            <input type="submit" value="<?php echo $lang["propose_changes_buttontext"] ?>" onClick="ShowProposeChanges(<?php echo $field["ref"] ?>);return false;" />
            </div>
            <?php
            }?>

    <div class="proposed_change proposed_change_value proposed ProposeChangesProposed" <?php if($proposed_value==""){echo "style=\"display:none;\""; } ?> id="proposed_change_<?php echo $field["ref"] ?>">
    <input type="hidden" id="propose_change_<?php echo $field["ref"] ?>" name="propose_change_<?php echo $field["ref"] ?>" value="true" <?php if($proposed_value==""){echo "disabled=\"disabled\""; } ?> />
    <?php
    # ----------------------------  Show field -----------------------------------
    // Checkif we have a proposed value for this field
    if('' != $proposed_value)
        {
        $value = $proposed_value;
        }
    else
        {
        $value = $realvalue;
        }

    $type = $field['type'];

    if('' == $type)
        {
        $type = 0;
        }

    if (!hook('replacefield', '', array($field['type'], $field['ref'], $n)))
        {
        global $auto_order_checkbox, $auto_order_checkbox_case_insensitive, $FIXED_LIST_FIELD_TYPES, $is_search;

        if(in_array($field['type'], $FIXED_LIST_FIELD_TYPES))
            {
            $name = "nodes[{$field['ref']}]";

            // Sometimes we need to pass multiple options
            if(in_array($field['type'], array(FIELD_TYPE_CHECK_BOX_LIST, FIELD_TYPE_CATEGORY_TREE)))
                {
                $name = "nodes[{$field['ref']}][]";
                }
            else if(FIELD_TYPE_DYNAMIC_KEYWORDS_LIST == $field['type'])
                {
                $name = "field_{$field['ref']}";
                }

            $selected_nodes = (trim($proposed_value) != "" ? explode(', ', $proposed_value) : array());
            if(!$editaccess && '' == $proposed_value)
                {
                $selected_nodes = get_resource_nodes($ref, $field['resource_type_field']);
                }
            }
        else if ($field["type"]==FIELD_TYPE_DATE_RANGE)
            {
            $rangedates = explode(",",$value);
            natsort($rangedates);
            $value=implode(",",$rangedates);
            }

        $is_search = false;

        include dirname(__FILE__) . "/../../../pages/edit_fields/{$type}.php";
        }
    # ----------------------------------------------------------------------------
    ?>
        </div><!-- close proposed_change_<?php echo $field["ref"] ?> -->
        <?php
        if($editaccess)
            {
            ?>     
            <div class="ProposeChangesAccept ProposeChangesAcceptDeleteColumn">
            <table>
            <tr>
            <td><input class="ProposeChangesAcceptCheckbox" type="checkbox" id="accept_change_<?php echo $field["ref"] ?>" name="accept_change_<?php echo $field["ref"] ?>" onchange="UpdateProposals(this,<?php echo $field["ref"] ?>);" checked ></input><?php echo $lang["propose_changes_accept_change"] ?></td>
            <td>
            <input class="ProposeChangesDeleteCheckbox" type="checkbox" id="delete_change_<?php echo $field["ref"] ?>" name="delete_change_<?php echo $field["ref"] ?>" onchange="DeleteProposal(this,<?php echo $field["ref"] ?>);" ></input><?php echo $lang["action-delete"] ?></td>
            </tr>
            </table>
            </div>
            <?php
            }

    if (trim($field["help_text"]!=""))
        {
        # Show inline help for this field.
        # For certain field types that have no obvious focus, the help always appears.
        ?>
        <div class="FormHelp" style="<?php if (!in_array($field["type"],array(2,4,6,7,10))) { ?>display:none;<?php } else { ?>clear:left;<?php } ?>" id="help_<?php echo $field["ref"]?>"><div class="FormHelpInner"><?php echo nl2br(trim(htmlspecialchars(i18n_get_translated($field["help_text"],false))))?></div></div>
        <?php
        }

    # If enabled, include code to produce extra fields to allow multilingual free text to be entered.
    if ($multilingual_text_fields && ($field["type"]==0 || $field["type"]==1 || $field["type"]==5))
        {
        propose_changes_display_multilingual_text_field($n, $field, $translations);
        }
    ?>
    <div class="clearerleft"> </div>
    </div><!-- end of question_<?php echo $n?> div -->
    <?php
    // Don't show this if user is an admin viewing proposed changes
    if ($editaccess && $proposed_value=="")
        {
        ?>
        </div><!-- End of hidden field -->
        <?php
        }
    }

// End of functions, start rendering the page

include "../../../include/header.php";


if (isset($resulttext))
    {
    echo "<div class=\"PageInformal \">" . $resulttext . "</div>";
    }

$searchparams = get_search_params();
if(!$modal)
    {
    ?>
    <p><a href="<?php echo generateurl($baseurl . "/pages/view.php",$searchparams,["ref" => $ref]); ?>" onClick="return  <?php echo ($modal?"Modal":"CentralSpace") ?>Load(this,true);"><?php echo LINK_CARET_BACK ?><?php echo $lang["backtoresourceview"]?></a></p>
    <?php
    }
    ?>
<div class="BasicsBox" id="propose_changes_box">
<h1 id="editresource">
<?php
if(!$editaccess)
    { 
    echo $lang['propose_changes_short'];
    }
else
    {
    echo $lang['propose_changes_review_proposed_changes'];
    }
?>
</h1>
<p>
<?php
if(!$editaccess)
    {
    echo $lang['propose_changes_text'];
    }
?>
</p>
    <?php
    if ($resource["has_image"]==1)
        {
        ?><img src="<?php echo get_resource_path($ref,false,"thm",false,$resource["preview_extension"],-1,1,checkperm("w"))?>" class="ImageBorder" style="margin-right:10px;"/>
        <?php
        }
    else
        {
        # Show the no-preview icon
        ?>
        <img src="<?php echo $baseurl_short ?>gfx/<?php echo get_nopreview_icon($resource["resource_type"],$resource["file_extension"],true)?>" />
        <?php
        }
    ?>

    <div class="Question" id="resource_ref_div" style="border-top:none;">
        <label><?php echo $lang["resourceid"]?></label>
        <div class="Fixed"><?php echo urlencode($ref) ?></div>
        <div class="clearerleft"> </div>
    </div>
    <?php

    if($editaccess && count($userproposals)>0)
        {
        ?>
        <div class="Question" id="ProposeChangesUsers">
        <form id="propose_changes_select_user_form" method="post" action="<?php echo generateurl($baseurl . "/plugins/propose_changes/pages/propose_changes.php",$searchparams,["ref" => $ref]); ?>" onsubmit="return <?php echo ($modal?"Modal":"CentralSpace") ?>Post(this,true);">
            <?php generateFormToken("propose_changes_select_user_form"); ?>
            <label><?php echo $lang["propose_changes_view_user"]; ?></label>
            <?php
            if(count($userproposals) > 1)
                {?>
                <select class="stdwidth" name="proposeuser" id="proposeuser" onchange="return <?php echo ($modal?"Modal":"CentralSpace") ?>Post(document.getElementById('propose_changes_select_user_form'),false);">
                <?php 
                foreach ($userproposals as $userproposal)
                    {
                    echo  "<option value=" . $userproposal["user"] . " " . (($view_user==$userproposal["user"])?"selected":"") . ">" . htmlspecialchars($userproposal["username"]) . "</option>";
                    }
                ?>
                </select>
                <?php
                }
            else
                {
                ?>
                <div class="Fixed"><?php echo htmlspecialchars($userproposals[0]["username"]) ?></div>
                <?php
                }
                ?>
        </form>
        <div class="clearerleft"> </div>
        </div>
        <?php
        }

    $display_any_fields=false;
    $fieldcount=0;
    for ($n=0;$n<count($proposefields);$n++)
        {
        node_field_options_override($proposefields[$n]);

        if (propose_changes_is_field_displayed($proposefields[$n]))
            {
            $proposefields[$n]["display"]=true;
            $display_any_fields=true;
            break;
            }
        }
    if ($display_any_fields)
        {
        ?>
        
    <form id="propose_changes_form" method="post" action="<?php echo generateurl($baseurl . "/plugins/propose_changes/pages/propose_changes.php",$searchparams,["ref" => $ref]); ?>"  onsubmit="return <?php echo ($modal?"Modal":"CentralSpace") ?>Post(this,true);">
    <?php generateFormToken("propose_changes_form"); ?>
    <h2 id="ProposeChangesHead"><?php echo $lang["propose_changes_proposed_changes"] ?></h2><?php
        ?><div id="ProposeChangesSection">
                <div class="Question ProposeChangesQuestion" id="propose_changes_field_header" >
                        
                <div class="ProposeChangesTitle ProposeChangesLabel" ><?php echo $lang["propose_changes_field_name"] ?></div>                
                <div class="ProposeChangesTitle ProposeChangesCurrent"><?php echo $lang["propose_changes_current_value"] ?></div>
                <div class="ProposeChangesTitle ProposeChangesProposed" ><?php echo $lang["propose_changes_proposed_value"] ?></div>
                
                <?php
                if($editaccess)
                    {
                    ?> 
                    <div class="ProposeChangesTitle ProposeChangesAcceptDeleteColumn" id="ProposeChangesAcceptDeleteColumn">
                    <table>
                    <tr>
                    <td>
                    <input id="ProposeChangesAcceptAllCheckbox" class="ProposeChangesAcceptCheckbox" type="checkbox" name="accept_all_changes" onClick="ProposeChangesUpdateAll(this);" checked ><?php echo $lang["propose_changes_accept_change"] ?>
                    </td>
                    <td>
                    <input id="ProposeChangesDeleteAllCheckbox" class="ProposeChangesDeleteCheckbox" type="checkbox" name="delete_all_changes" onClick="ProposeChangesDeleteAll(this);" ><?php echo $lang["action-delete"] ?>
                    </td>
                    </tr>
                    </table>
                    </div>
                    <?php
                    }
                ?>              
                <div class="clearerleft"> </div>
                </div><!-- End of propose_changes_field_header -->
        <?php
        }

    for ($n=0;$n<count($proposefields);$n++)
        {
        node_field_options_override($proposefields[$n]);

        # Should this field be displayed?
        if ((isset($proposefields[$n]["display"]) && $proposefields[$n]["display"]==true) || propose_changes_is_field_displayed($proposefields[$n]))
            {	
            $fieldcount++;
            propose_changes_display_field($n, $proposefields[$n]);
            }
        }	

    // Let admin know there are no proposed changes anymore for this resources
    // Can happen when another admin already reviewed the changes.
    $changes_to_review_counter = 0;
    foreach($proposefields as $propose_field)
        {
        foreach($proposed_changes as $proposed_change)
            {
            if($proposed_change['resource_type_field'] == $propose_field['ref'])
                {
                $changes_to_review_counter++;
                }
            }
        }

    if($editaccess && empty($propose_changes) && $changes_to_review_counter == 0)
        {
        ?>
        <div id="message" class="Question ProposeChangesQuestion">
            <?php echo $lang['propose_changes_no_changes_to_review']; ?>
        </div>
        <?php
        }?>

    <div class="QuestionSubmit">
        <input id="resetform" name="resetform" type="hidden" value=""/>
        <input id="save"  name="submitted" type="hidden" value="" />
        <input name="proposeuser" type="hidden" value="<?php echo isset($view_user) ? htmlspecialchars($view_user) : ""?>" />
        <input name="resetform" type="submit" value="<?php echo $lang["clearbutton"]?>" onClick="return jQuery('#resetform').val('true');"/>&nbsp;
            <?php if($editaccess)
                {?>
                <input name="save" type="submit" value="&nbsp;&nbsp;<?php echo $lang["propose_changes_save_changes"]?>&nbsp;&nbsp;" onClick="return jQuery('#save').val('true');"/><br />
                <?php
                }
            else
                {?>
                <input name="save" type="submit" value="&nbsp;&nbsp;<?php echo $lang["save"]?>&nbsp;&nbsp;"  onClick="return jQuery('#save').val('true');"/><br />
                <?php
                }
                    ?>
        <div class="clearerleft"> </div>
    </div>

</form><!-- End of propose_changes_form -->

</div><!-- End of propose_changes_box -->
</div><!-- End of BasicsBox -->
<script>

function ShowHelp(field)
    {
    // Show the help box if available.
    if (document.getElementById('help_' + field))
        {
       jQuery('#help_' + field).fadeIn();
        }
    }

function HideHelp(field)
    {
    // Hide the help box if available.
    if (document.getElementById('help_' + field))
        {
        document.getElementById('help_' + field).style.display='none';
        }
    }

function ShowProposeChanges(fieldref)
    {
    jQuery('#proposed_change_' + fieldref).show();
    jQuery('#propose_change_button_' + fieldref).hide();
    return false;
    }

function UpdateProposals(checkbox, fieldref)
    {
    if (checkbox.checked)
        {
        jQuery('#field_' + fieldref).prop('disabled',false); 
        jQuery('#propose_change_' + fieldref).prop('disabled',false);
        checkprefix="input[id^=" + fieldref + "_]";		
        jQuery(checkprefix).prop('disabled',false); // Enable checkboxes
        }
    else
        {
        jQuery('#field_' + fieldref).prop('disabled',true);
        jQuery('#propose_change_' + fieldref).prop('disabled',true);
        }
    }

function DeleteProposal(checkbox, fieldref)
    {
    if (checkbox.checked)
        {
        jQuery('#field_' + fieldref).prop('disabled',true);
        checkprefix="input[id^=" + fieldref + "_]";
        jQuery(checkprefix).prop('disabled',true); // Disable checkboxes
        jQuery('#accept_change_' + fieldref).prop('checked',false);
        jQuery('#accept_change_' + fieldref).prop('disabled',true);
        }
    else
        {
        jQuery('#accept_change_' + fieldref).prop('disabled',false);
        }
    }

function ProposeChangesUpdateAll(checkbox)
    {
    if (checkbox.checked)
        {
        jQuery('.ProposeChangesAcceptCheckbox').prop('checked',true);
        jQuery('.ProposeChangesDeleteCheckbox').prop('checked',false);
        jQuery('.ProposeChangesAcceptCheckbox').prop('disabled',false);
        }
    else
        {
        jQuery('.ProposeChangesAcceptCheckbox').prop('checked',false);
        }
    jQuery('.ProposeChangesAcceptCheckbox').trigger('change');
    }

function ProposeChangesDeleteAll(checkbox)
    {
    if (checkbox.checked)
        {
        jQuery('.ProposeChangesDeleteCheckbox').prop('checked',true);
        jQuery('.ProposeChangesAcceptCheckbox').prop('checked',false);
        jQuery('.ProposeChangesAcceptCheckbox').prop('disabled',true);
        }
    else
        {
        jQuery('.ProposeChangesDeleteCheckbox').prop('checked',false);
        jQuery('.ProposeChangesAcceptCheckbox').prop('disabled',false);
        }

    jQuery('.ProposeChangesAcceptCheckbox').trigger('change');
    }
</script>

<?php 

include "../../../include/footer.php";

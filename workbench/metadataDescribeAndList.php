<?php
require_once 'session.php';
require_once 'shared.php';
require_once 'header.php';
print "<p/>";
if (!apiVersionIsAtLeast(10.0)) {
    show_error("Metadata API not supported prior to version 10.0", false, true);
    exit;
}

require_once 'soapclient/SforceMetadataClient.php';

global $metadataConnection;
try {
    $describeMetadataResult = $metadataConnection->describeMetadata(getApiVersion());
} catch (Exception $e) {
    show_error($e->getMessage(), false, true);
}

$metadataTypesSelectOptions[""] = "";
foreach ($describeMetadataResult as $resultsKey => $resultsValue) {
    if ($resultsKey == 'metadataObjects') {
        foreach ($resultsValue as $metadataResultsKey => $metadataResultsValue) {
            $metadataTypeMap[$metadataResultsValue->xmlName] = $metadataResultsValue;
            $metadataTypesSelectOptions[$metadataResultsValue->xmlName]= $metadataResultsValue->xmlName;

            if (isset($metadataResultsValue->childXmlNames)) {
                if (!is_array($metadataResultsValue->childXmlNames)) {
                    $metadataResultsValue->childXmlNames = array($metadataResultsValue->childXmlNames);
                }

                foreach ($metadataResultsValue->childXmlNames as $childNameKey => $childName) {
                    $metadataTypesSelectOptions[$childName]= $childName;

                    $childType = new stdClass();
                    $childType->parentXmlName = $metadataResultsValue->xmlName .
                        " <a href='" . $_SERVER['PHP_SELF'] . "?type=$metadataResultsValue->xmlName' class='miniLink' onClick=\"document.getElementById('loadingMessage').style.visibility='visible';\">[INFO]</a>";
                    $childType->childXmlName = $childName;
                    $metadataTypeMap[$childName] = $childType;

                    $metadataTypeMap[$metadataResultsValue->xmlName]->childXmlNames[$childNameKey] = $childName .
                        " <a href='" . $_SERVER['PHP_SELF'] . "?type=$childName' class='miniLink' onClick=\"document.getElementById('loadingMessage').style.visibility='visible';\">[INFO]</a>";
                }
            }
        }
    }
}

$metadataTypesSelectOptions = natcaseksort($metadataTypesSelectOptions);

$currentTypeString = isset($_REQUEST['type']) ? htmlentities($_REQUEST['type']) : null;
$previousTypeString = isset($_SESSION['defaultMetadataType']) ? $_SESSION['defaultMetadataType'] : null;
$typeString = $currentTypeString != null ? $currentTypeString : $previousTypeString;
$typeStringChanged = $currentTypeString != null && $previousTypeString != $currentTypeString;

?>
<p class='instructions'>Choose a metadata type describe and list its
components:</p>
<form id="metadataTypeSelectionForm" name="metadataTypeSelectionForm"
    method="GET" action="<?php print $_SERVER['PHP_SELF']; ?>"><select
    id="type" name="type"
    onChange="document.getElementById('loadingMessage').style.visibility='visible'; document.metadataTypeSelectionForm.submit();">
    <?php printSelectOptions($metadataTypesSelectOptions, $typeString); ?>
</select> <span id='loadingMessage'
    style='visibility: hidden; color: #888;'>&nbsp;&nbsp;<img
    src='images/wait16trans.gif' align='absmiddle' /> Loading...</span>
</form>
<p />

    <?php
    if (isset($typeString)) {
        if (!isset($metadataTypeMap[$typeString])) {
            if (isset($_REQUEST['type']) && $_REQUEST['type']) {
                show_error("Invalid metadata type type: $typeString", false, true);
            }
            exit;
        }
        $type = $metadataTypeMap[$typeString];
        $_SESSION['defaultMetadataType'] = $typeString;

        $metadataComponents = listMetadata($type);

        printTree("listMetadataTree", array("Type Description"=>$type, "Components"=>$metadataComponents), $typeStringChanged);
    }

    require_once 'footer.php';


    function listMetadata($type) {
        global $metadataConnection;
        global $partnerConnection;

        try {
            if (isset($type->childXmlName)) {
                return processListMetadataResult($metadataConnection->listMetadata($type->childXmlName, null, getApiVersion()));
            }

            if (!$type->inFolder) {
                return processListMetadataResult($metadataConnection->listMetadata($type->xmlName, null, getApiVersion()));
            }

            $folderQueryResult = $partnerConnection->query("SELECT DeveloperName FROM Folder WHERE Type = '" . $type->xmlName . "' AND DeveloperName != null AND NamespacePrefix = null");

            if ($folderQueryResult->size == 0) {
                return array();
            }

            foreach ($folderQueryResult->records as $folderRecord) {
                $folder = new SObject($folderRecord);
                $folderName = $folder->fields->DeveloperName;

                $listMetadataResult["$folderName"] = processListMetadataResult($metadataConnection->listMetadata($type->xmlName, $folder->fields->DeveloperName, getApiVersion()));
            }

            return $listMetadataResult;
        } catch (Exception $e) {
            show_error($e->getMessage(), false, true);
        }
    }

    function processListMetadataResult($response) {
        if (!is_array($response)) {
            $response = array($response);
        }

        $processedResponse = array();
        foreach ($response as $responseKey => $responseValue) {
            if ($responseValue == null) {
                continue;
            }

            $name = isset($responseValue->fullName) ? $responseValue->fullName : $responseValue->fileName;
            if (strrchr($name, "/")) {
                $simpleName = substr(strrchr($name, "/"), 1);
                $processedResponse[$simpleName] = $responseValue;
            } else if (strpos($name, ".")) {
                $parentName = substr($name, 0, strpos($name, "."));
                $childName = substr($name, strpos($name, ".") + 1);
                $processedResponse[$parentName][$childName] = $responseValue;
            } else {
                $processedResponse[$name] = $responseValue;
            }
        }
        $processedResponse = natcaseksort($processedResponse);

        return $processedResponse;
    }
    ?>
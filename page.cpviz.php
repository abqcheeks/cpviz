<?php /* $Id */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2013 Schmooze Com Inc.
//  Copyright (C) 2011 Mikael Carlsson (mickecarlsson at gmail dot com)
//

// load graphviz library
require_once 'graphviz/src/Alom/Graphviz/InstructionInterface.php';
require_once 'graphviz/src/Alom/Graphviz/BaseInstruction.php';
require_once 'graphviz/src/Alom/Graphviz/Node.php';
require_once 'graphviz/src/Alom/Graphviz/Edge.php';
require_once 'graphviz/src/Alom/Graphviz/DirectedEdge.php';
require_once 'graphviz/src/Alom/Graphviz/AttributeBag.php';
require_once 'graphviz/src/Alom/Graphviz/Graph.php';
require_once 'graphviz/src/Alom/Graphviz/Digraph.php';
require_once 'graphviz/src/Alom/Graphviz/AttributeSet.php';
require_once 'graphviz/src/Alom/Graphviz/Subgraph.php';

//$extension = _("Extension");
//$vmxlocator = _("VmX Locator");
//$followme   = _("Follow-Me");
//$callstatus = _("Call status");
//$status     =_("Status");
//$html_txt_arr = array();
//$module_select = array();
//global $active_modules;

$action = isset($_POST['action']) ? $_POST['action'] : '';
$extdisplay = isset($_POST['extdisplay']) ? $_POST['extdisplay'] : '';
$iroute = isset($_POST['iroute']) ? $_POST['iroute'] : '';
 
$html_txt = '<div class="content">';
$html_txt .= '<br><h2>'._("FreePBX Call Plan Vizualizer").'</h2>';

$full_list = framework_check_extension_usage(true);
$full_list = is_array($full_list)?$full_list:array();
// Dont waste astman calls, get all family keys in one call
// Get all AMPUSER settings
// $ampuser = $astman->database_show("AMPUSER");
// get all QUEUE settings
$queuesetting = $astman->database_show("QUEUE");
// $allsetting = $astman->database_show('');
// $html_txt .= "<pre>\n" . "ALL settings: " . print_r($allsetting, true) .
//              "\n</pre><br>\n";


// Output a selector for the users to choose an inbound route
$inroutes = dp_load_incoming_routes();

$html_txt .= "<form name=\"routePrompt\" action=\"$_SERVER[PHP_SELF]\" method=\"POST\">\n";
$html_txt .= "<input type=\"hidden\" name=\"display\" value=\"cpviz\">\n";
$html_txt .= "Select an inbound route: ";
$html_txt .= "<select name=\"iroute\">\n";
$html_txt .= "<option value=\"None\">Select A Route</option>\n";
foreach ($inroutes as $ir) {
  $s = ($ir['extension'] == $iroute) ? "selected" : "";
  $html_txt .= "<option value=\"$ir[extension]\" $s>$ir[extension]</option>\n";
}
$html_txt .= "</select>\n";
$html_txt .= "<input name=\"Submit\" type=\"submit\" value=\"Visualize Call Plan\">\n";
$html_txt .= "</form>\n";
$html_txt .= "<br>\n";

// Now, if $iroute is set, we will procede to display the call plan
// graph for it.  If not, we would like to just bail, but I haven't
// figured out how to do that in this framework.  If I exit() or 
// throw an exception, then the page doesn't finish loading, no CSS
// happens, it looks ugly like something went really wrong.
//$iroute = '5052327992';
if ($iroute != '') {

  $dproute = dp_find_route($inroutes, $iroute);
  if (empty($dproute)) {
    $html_txt .= "<h2>Error: Could not find inbound route for '$iroute'</h2>\n";
    // ugh: throw new \InvalidArgumentException("Could not find and inbound route for '$iroute'");
  } else {

    // $html_txt .= "<pre>\n" . "$iroute route: " . print_r($dproute, true) . "\n</pre><br>\n";

    dp_load_tables($dproute);   # adds data for time conditions, IVRs, etc.
    //$html_txt .= "<pre>\n" . "FreePBX config data: " . print_r($dproute, true) . "\n</pre><br>\n";

    dplog(5, "Doing follow dest ...");
    dp_follow_destinations($dproute, '');
    dplog(5, "Finished follow dest ...");

    $gtext = $dproute['dpgraph']->render();
    // $html_txt .= "<pre>\n" . "Dial Plan Graph for $iroute:\n$gtext" . "\n</pre><br>\n";
    dplog(5, "Dial Plan Graph for $iroute:\n$gtext");
    $gtext = preg_replace("/\n/", " ", $gtext);  // ugh, apparently viz chokes on newlines, wtf?


    $html_txt .= "<script src=\"modules/cpviz/viz.js\"></script>\n";
    $html_txt .= "<script src=\"modules/cpviz/full.render.js\"></script>\n";
    $html_txt .= "<div id='vizContainer'><h1>Call Plan For Inbound Route $iroute</h1></div>\n";
    $html_txt .= "<script type=\"text/javascript\">\n";
    $html_txt .= "    var viz = new Viz();\n";
    // $html_txt .= " viz.renderSVGElement('$gtext')  \n";
    // $html_txt .= " viz.renderSVGElement('digraph { a -> b; }')  \n";
    // $html_txt .= " viz.renderSVGElement('digraph 5052327992 { \"5052327992\" [label=\"5052327992\", style=filled, fillcolor=\"#7979FF\"]; }')  \n";
    $html_txt .= " viz.renderSVGElement('$gtext')  \n";
    $html_txt .= "   .then(function(element) {                 \n";
    $html_txt .= "     document.getElementById(\"vizContainer\").appendChild(element);   \n";
    $html_txt .= "  });\n";
    $html_txt .= "</script>\n";
  }
}

echo $html_txt."</div>";
?>

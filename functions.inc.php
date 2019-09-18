<?php 
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Log Level: 0 = total quiet, 9 = much verbose
$dp_log_level = 6;

// Set some colors
$pastels[] = "#7979FF";
$pastels[] = "#86BCFF";
$pastels[] = "#8ADCFF";
$pastels[] = "#3DE4FC";
$pastels[] = "#5FFEF7";
$pastels[] = "#33FDC0";
$pastels[] = "#4BFE78";

$neons[] = "#fe0000";
$neons[] = "#fdfe02";
$neons[] = "#0bff01";
$neons[] = "#011efe";
$neons[] = "#fe00f6";

function dp_load_incoming_routes() {
  global $db;

  $sql = "select * from incoming";
  $results = $db->getAll($sql, DB_FETCHMODE_ASSOC);
  if (DB::IsError($results)) {
    die_freepbx($results->getMessage()."<br><br>Error selecting from incoming");       
  }

  // Store the routes in a hash indexed by the inbound number
  foreach($results as $route) {
    $num = $route['extension'];
    $routes[$num] = $route;
  }
  return $routes;
}

function dp_find_route($routes, $num) {

  $match = array();
  $pattern = '/[^0-9]/';   # remove all non-digits
  $num =  preg_replace($pattern, '', $num);

  // "extension" is the key for the routes hash
  foreach ($routes as $ext => $route) {
    if ($ext == $num) {
      $match = $routes[$num];
    }
  }
  return $match;
}

#
# This is a recursive function.  It digs through various nodes
# (ring groups, ivrs, time conditions, extensions, etc.) to find
# the path a call takes.  It creates a graph of the path through
# the dial plan, stored in the $route object.
#
#
function dp_follow_destinations (&$route, $destination) {
  global $db;
  global $pastels;
  global $neons;

  if (! isset ($route['dpgraph'])) {
    $route['dpgraph'] = new Alom\Graphviz\Digraph($route['extension']);
  }
  $dpgraph = $route['dpgraph'];
  //dplog(9, "dpgraph: " . print_r($dpgraph, true));
  dplog(9, "destination='$destination' route[extension]: " . print_r($route['extension'], true));

  # This only happens on the first call.  Every recursive call includes
  # a destination to look at.  For the first one, we get the destination from
  # the route object.
  if ($destination == '') {
    $dpgraph->node($route['extension'],
                   array('label' => $route['extension'],
                         'style' => 'filled',
                         'fillcolor' => $pastels[0]));
    // $graph->node() returns the graph, not the node, so we always
    // have to get() the node after adding to the graph if we want
    // to save it for something.
    // UPDATE: beginNode() creates a node and returns it instead of
    // returning the graph.  Similarly for edge() and beginEdge().
    $route['parent_node'] = $dpgraph->get($route['extension']);
    $route['parent_edge_label'] = 'Always';

    # One of thse should work to set the root node, but neither does.
    # See: https://rt.cpan.org/Public/Bug/Display.html?id=101437
    #$route->{parent_node}->set_attribute('root', 'true');
    #$dpgraph->set_attribute('root' => $route->{extension});

    // If an inbound route has no destination, we want to bail, otherwise recurse.
    if ($route['destination'] != '') {
      dp_follow_destinations($route, $route['destination']);
    }
    return;
  }

  dplog(9, "Inspecting destination $destination");

  // We use get() to see if the node exists before creating it.  get() throws
  // an exception if the node does not exist so we have to catch it.
  try {
    $node = $dpgraph->get($destination);
  } catch (Exception $e) {
    dplog(7, "Adding node: $destination");
    $node = $dpgraph->beginNode($destination);
  }
  
  // Add an edge from our parent to this node, if there is not already one.
  // We do this even if the node already existed because this node might
  // have several paths to reach it.
  $ptxt = $route['parent_node']->getAttribute('label', '');
  $ntxt = $node->getAttribute('label', '');
  dplog(9, "Found it: ntxt = $ntxt");
  if ($ntxt == '' ) { $ntxt = "(new node: $destination)"; }
  if ($dpgraph->hasEdge(array($route['parent_node'], $node))) {
    dplog(9, "NOT making an edge from $ptxt -> $ntxt");
  } else {
    dplog(9, "Making an edge from $ptxt -> $ntxt");
    $edge = $dpgraph->beginEdge(array($route['parent_node'], $node));
    $edge->attribute('label', $route['parent_edge_label']);
  }

  // dplog(9, "The Graph: " . print_r($dpgraph, true));

  // Now bail if we have already recursed on this destination before.
  if ($node->getAttribute('label', 'NONE') != 'NONE') {
    return;
  }

  # Now look at the destination and figure out where to dig deeper.

  #
  # Time Conditions
  #
  if (preg_match("/^timeconditions,(\d+),(\d+)/", $destination, $matches)) {
    $tcnum = $matches[1];
    $tcother = $matches[2];
  
    $tc = $route['timeconditions'][$tcnum];
    $node->attribute('label', "TC: $tc[displayname]");
    $node->attribute('fillcolor', $pastels[1]);
    $node->attribute('style', 'filled');
  
    # Not going to use the time group info for right now.  Maybe put it in the edge text?
    #$tgname = $route['timegroups'][$tc['time']]['description'];
    #$tgtime = $route['timegroups'][$tc['time']]['time'];

    # Now set the current node to be the parent and recurse on both the true and false branches
    $route['parent_edge_label'] = 'Match';
    $route['parent_node'] = $node;
    dp_follow_destinations($route, $tc['truegoto']);

    $route['parent_edge_label'] = 'NoMatch';
    $route['parent_node'] = $node;
    dp_follow_destinations($route, $tc['falsegoto']);

  //
  // Queues
  //
  } elseif (preg_match("/^ext-queues,(\d+),(\d+)/", $destination, $matches)) {
    $qnum = $matches[1];
    $qother = $matches[2];

    $q = $route['queues'][$qnum];
    $node->attribute('label', "Queue: $q[descr]");
    $node->attribute('fillcolor', $pastels[2]);
    $node->attribute('style', 'filled');

    # The destinations we need to follow are the queue members (extensions)
    # and the no-answer destination.
    if ($q['dest'] != '') {
      $route['parent_edge_label'] = 'No Answer';
      $route['parent_node'] = $node;
      dp_follow_destinations($route, $q['dest']);
    }

    ksort($q['members']);
    foreach ($q['members'] as $member => $qstatus) {
      dplog(9, "queue member $member / $qstatus ...");
      if ($qstatus == 'static') {
        $route['parent_edge_label'] = 'Static Member';
      } else {
        $route['parent_edge_label'] = 'Dynamic Member';
      }
      $route['parent_node'] = $node;
      dp_follow_destinations($route, $member);
    }

  #
  # IVRs
  #
  } elseif (preg_match("/^ivr-(\d+),([a-z]+),(\d+)/", $destination, $matches)) {
    $inum = $matches[1];
    $iflag = $matches[2];
    $iother = $matches[3];

    $ivr = $route['ivrs'][$inum];
    $node->attribute('label', "IVR: $ivr[name]");
    $node->attribute('fillcolor', $pastels[3]);
    $node->attribute('style', 'filled');

    # The destinations we need to follow are the invalid_destination,
    # timeout_destination, and the selection targets
    if ($ivr['invalid_destination'] != '') {
      $route['parent_edge_label'] = 'Invalid Input';
      $route['parent_node'] = $node;
      dp_follow_destinations($route, $ivr['invalid_destination']);
    }
    if ($ivr['timeout_destination'] != '') {
      $route['parent_edge_label'] = 'Timeout';
      $route['parent_node'] = $node;
      dp_follow_destinations($route, $ivr['timeout_destination']);
    }
    # print "IVR: ". Dumper($ivr);
    ksort($ivr['entries']);
    foreach ($ivr['entries'] as $selid => $ent) {
      dplog(9, "ivr member $selid / $ent ...");
      $route['parent_edge_label'] = "Selection $ent[selection]";
      $route['parent_node'] = $node;
      dp_follow_destinations($route, $ent['dest']);
    }

  #
  # Ring Groups
  #
  } elseif (preg_match("/^ext-group,(\d+),(\d+)/", $destination, $matches)) {
    $rgnum = $matches[1];
    $rgother = $matches[2];

    $rg = $route['ringgroups'][$rgnum];
    $node->attribute('label', "RG: $rg[description]");
    $node->attribute('fillcolor', $pastels[4]);
    $node->attribute('style', 'filled');

    # The destinations we need to follow are the no-answer destination
    # (postdest) and the members of the group.
    if ($rg['postdest'] != '') {
      $route['parent_edge_label'] = 'No Answer';
      $route['parent_node'] = $node;
      dp_follow_destinations($route, $rg['postdest']);
    }

    ksort($rg['members']);
    foreach ($rg['members'] as $member => $junk) {
      $route['parent_edge_label'] = 'RG Member';
      $route['parent_node'] = $node;
      if (preg_match("/^\d+/", $member)) {
        dp_follow_destinations($route, "Ext$member");
      } elseif (preg_match("/#$/", $member)) {
        preg_replace("/[^0-9]/", '', $member);   // remove non-digits
        if (preg_match("/^(\d\d\d)(\d\d\d\d)$/", $member, $matches)) {
          $member = "$matches[1]-$matches[2]";
        } elseif (preg_match("/^(\d\d\d)(\d\d\d)(\d\d\d\d)$/", $member, $matches))  {
          $member = "$matches[1]-$matches[2]-$matches[3]";
        }
        dp_follow_destinations($route, "Callout $member");
      } else {
        dp_follow_destinations($route, "$member");
      }
    }  # end of ring groups

  } else {
    dplog(1, "Unknown destination type: $destination");
    if ($route['parent_edge_label'] == 'Dynamic Member') {
      $node->attribute('fillcolor', $neons[1]);
    } else {
      $node->attribute('fillcolor', $pastels[5]);
    }
    $node->attribute('style', 'filled');
  }

#print "dpgraph: " . Dumper($dpgraph);
}

/*
function dp_timecondition_str {
  local($route, $num) = @_;

  if (! defined $route->{timeconditions}->{$num}) {
    return "TIME CONDITION NOT FOUND";
  }

  $tc = $route->{timeconditions}->{$num};
  $tcname = $tc->{displayname};
  $tcyes = $tc->{truegoto};
  $tcno = $tc->{falsegoto};
  $tgname = $route->{timegroups}->{$tc->{time}}->{description};
  $tgtime = $route->{timegroups}->{$tc->{time}}->{time};
  return "$tcname $tcyes $tcno $tgname $tgtime";
}
*/

# load gobs of data.  Save it in hashrefs indexed by ints
function dp_load_tables(&$dproute) {
  global $db;

  # Time Conditions
  $query = "select * from timeconditions";
  $results = $db->getAll($query, DB_FETCHMODE_ASSOC);
  if (DB::IsError($results)) {
    die_freepbx($results->getMessage()."<br><br>Error selecting from timeconditions");       
  }
  foreach($results as $tc) {
    $id = $tc['timeconditions_id'];
    $dproute['timeconditions'][$id] = $tc;
  }

  # Time Groups
  $query = "select * from timegroups_groups";
  $results = $db->getAll($query, DB_FETCHMODE_ASSOC);
  if (DB::IsError($results)) {
    die_freepbx($results->getMessage()."<br><br>Error selecting from timegroups_groups");
  }
  foreach($results as $tg) {
    $id = $tg['id'];
    $dproute['timegroups'][$id] = $tg;
  }

  # Time Groups Details
  $query = "select * from timegroups_details";
  $results = $db->getAll($query, DB_FETCHMODE_ASSOC);
  if (DB::IsError($results)) {
    die_freepbx($results->getMessage()."<br><br>Error selecting from timegroups_details");       
  }
  foreach($results as $tgd) {
    $id = $tgd['timegroupid'];
    if (! isset($dproute['timegroups'][$id])) {
      dplog(1, "timegroups_details id found for unknown timegroup, id=$id");
    } else {
      $dproute['timegroups'][$id]['time'] .= $tgd['time'];
      $dproute['timegroups'][$id]['time'] .= "\n";
    }
  }


  # Queues
  $query = "select * from queues_config";
  $results = $db->getAll($query, DB_FETCHMODE_ASSOC);
  if (DB::IsError($results)) {
    die_freepbx($results->getMessage()."<br><br>Error selecting from timegroups_details");       
  }
  foreach($results as $q) {
    $id = $q['extension'];
    $dproute['queues'][$id] = $q;
  }

  # Queue members
  $query = "select * from queues_details";
  $results = $db->getAll($query, DB_FETCHMODE_ASSOC);
  if (DB::IsError($results)) {
    die_freepbx($results->getMessage()."<br><br>Error selecting from timegroups_details");       
  }
  foreach($results as $qd) {
    $id = $qd['id'];
    if ($qd['keyword'] == 'member') {
      $member = $qd['data'];
      if (preg_match("/Local\/(\d+)/", $member, $matches)) {
        $enum = $matches[1];
        $dproute['queues'][$id]['members']["Ext$enum"] = 'static';
      }
    }
  }

/*
  # Info about dynamic queue members is stored in AstDB, not mysql
  &dp_load_dynamic_queue_members($dproute->{queues});
*/

  # IVRs
  $query = "select * from ivr_details";
  $results = $db->getAll($query, DB_FETCHMODE_ASSOC);
  if (DB::IsError($results)) {
    die_freepbx($results->getMessage()."<br><br>Error selecting from timegroups_details");       
  }
  foreach($results as $ivr) {
    $id = $ivr['id'];
    $dproute['ivrs'][$id] = $ivr;
  }

  # IVR entries
  $query = "select * from ivr_entries";
  $results = $db->getAll($query, DB_FETCHMODE_ASSOC);
  if (DB::IsError($results)) {
    die_freepbx($results->getMessage()."<br><br>Error selecting from timegroups_details");       
  }
  foreach($results as $ent) {
    $id = $ent['ivr_id'];
    $selid = $ent['selection'];
    dplog(9, "entry:  ivr=$id   selid=$selid");
    $dproute['ivrs'][$id]['entries'][$selid] = $ent;
  }

  # Ring Groups
  $query = "select * from ringgroups";
  $results = $db->getAll($query, DB_FETCHMODE_ASSOC);
  if (DB::IsError($results)) {
    die_freepbx($results->getMessage()."<br><br>Error selecting from timegroups_details");       
  }
  foreach($results as $rg) {
    $id = $rg['grpnum'];
    $dproute['ringgroups'][$id] = $rg;
    $dests = preg_split("/-/", $rg['grplist']);
    foreach ($dests as $dest) {
      dplog(9, "rg dest:  rg=$id   dest=$dest");
      $dproute['ringgroups'][$id]['members'][$dest] = 1;
    }
  }

}

/*
function dp_load_dynamic_queue_members {
  local($queues) = @_;
  local($cmd, $qid, $ext);

  $cmd = "/usr/sbin/asterisk -rx 'queue show'";
  open(CMD, "$cmd |") || die "Can't run cmd '$cmd': $!\n";
  while (<CMD>) {
    if (/^(\d+) has \d+ calls/) {
      $qid = $1;
      dplog(9, "Dynamic queue $qid");
    } elseif (/Local\/(\d+).*\(dynamic\)/) {
      $ext = $1;
      dplog(9, "Dynamic queue $qid, member $ext");
      $queues->{$qid}->{members}->{"Ext$ext"} = 'dynamic';
    }
  }
}
*/

function dplog($level, $msg) {
  global $dp_log_level;

  if ($dp_log_level < $level) {
    return;
  }

  $ts = date('m-d-Y H:i:s');
  if(! $fd = fopen("/tmp/dpviz.log", "a")) {
    print "Couldn't open log file.";
    exit;
  }
  fwrite($fd, $ts . "  " . $msg . "\n");
  fclose($fd);
  return;
}

?>

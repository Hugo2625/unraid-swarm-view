<?php
/* Mock of /plugins/dynamix.docker.manager/include/DockerContainers.php – same markup as Unraid 7.x */

function svtb_icon(string $name): string {
    $colors = ['#0082c9', '#c0765a', '#2f9e44', '#175ddc', '#18bcf2', '#7b3f98', '#e8b000'];
    $c = $colors[hexdec(substr(sha1($name), 0, 4)) % count($colors)];
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'><rect width='48' height='48' rx='10' fill='$c'/><text x='24' y='32' font-family='Arial' font-size='24' font-weight='bold' fill='#fff' text-anchor='middle'>" . strtoupper($name[0]) . "</text></svg>";
    return 'data:image/svg+xml;utf8,' . str_replace(['<', '>', '#', "'"], ['%3C', '%3E', '%23', '%27'], $svg);
}

function svtb_rows(array $S): string {
    $host = '192.168.1.10';
    $out = ''; $push = [];
    foreach (svtb_local($S) as $ct) {
        $name = $ct['name']; $id = $ct['id'];
        $running = $ct['running'] ? 1 : 0;
        $compose = $ct['compose'];
        $manager = $ct['manager'];
        $updateStatus = $manager === 'dockerman' ? 0 : 3;
        $menu = sprintf("onclick=\"addDockerContainerContext('%s','%s','%s',%s,%s,%s,%s,'%s','%s','%s','%s','%s','%s','%s', '%s','%s')\"",
            addslashes($name), 'sha256:' . $id, $manager === 'dockerman' ? "/boot/config/plugins/dockerMan/templates-user/my-$name.xml" : '',
            $running, 0, $updateStatus, $ct['autostart'] ? 'true' : 'false', '', '', 'sh', $id, '', '', '', '', '');
        $push[] = "docker.push({name:'$name',id:'$id',state:$running,pause:0,update:$updateStatus});";
        $shape = $running ? 'play' : 'square'; $status = $running ? 'started' : 'stopped'; $color = $running ? 'green-text' : 'red-text';
        $image = "<img src='" . svtb_icon($ct['short']) . "' class='img' onerror=\"this.onerror=null;this.src='/plugins/dynamix.docker.manager/images/question.png';\">";
        $appname = ($manager === 'dockerman' && !$compose) ? "<a class='exec' onclick=\"editContainer('" . addslashes($name) . "','x')\">" . htmlspecialchars($name) . "</a>" : htmlspecialchars($name);
        $out .= "<tr class='sortable'><td class='ct-name' style='width:220px;padding:8px'><i class='fa fa-arrows-v mover orange-text'></i>";
        $out .= "<span class='outer'><span id='$id' $menu class='hand'>$image</span><span class='inner'><span class='appname '>$appname</span><br><i id='load-$id' class='fa fa-$shape $status $color'></i><span class='state'>$status" . ($compose ? "<br/>Compose Stack: $compose" : '') . "</span></span></span>";
        [$repo, $tag] = array_pad(explode(':', $ct['image'], 2), 2, 'latest');
        $out .= "<div class='advanced' style='margin-top:8px'>Container ID: $id<br>By: " . htmlspecialchars($repo) . "</div></td><td class='updatecolumn'>";
        if ($updateStatus === 0) $out .= "<span class='green-text' style='white-space:nowrap;'><i class='fa fa-check fa-fw'></i> up-to-date</span><div class='advanced'><a class='exec'><span style='white-space:nowrap;'><i class='fa fa-cloud-download fa-fw'></i> force update</span></a></div>";
        elseif ($compose) $out .= "<div><span><i class='fa fa-docker fa-fw'/></i> Compose</span></div>";
        else $out .= "<div><span><i class='fa fa-docker fa-fw'/></i> 3rd Party</span></div>";
        $out .= "<div class='advanced'><i class='fa fa-info-circle fa-fw'></i> $tag</div></td>";
        $ip = $running ? '172.17.0.' . (hexdec(substr($id, 0, 2)) % 200 + 2) : '';
        $ports_in = implode('<br>', array_map(fn($p) => "$p:TCP", $ct['ports']));
        $ports_ex = implode('<br>', array_map(fn($p) => "$host:$p", $ct['ports']));
        $out .= "<td style='white-space:nowrap'><span class='docker_readmore'> {$ct['net']}</span></td>";
        $out .= "<td style='white-space:nowrap'><span class='docker_readmore'> $ip</span></td>";
        $out .= "<td style='white-space:nowrap'><span class='docker_readmore'>$ports_in</span></td>";
        $out .= "<td style='white-space:nowrap'><span class='docker_readmore'>$ports_ex</span></td>";
        $out .= "<td style='word-break:break-all'><span class='docker_readmore'>/config<i class=\"fa fa-arrows-h\" style=\"margin:0 6px\"></i>/mnt/user/appdata/{$ct['short']}</span></td>";
        $out .= "<td class='advanced'><span class='cpu-$id'>0%</span><div class='usage-disk mm'><span id='cpu-$id' style='width:0'></span><span></span></div><br><span class='mem-$id'>0 / 0</span></td>";
        if (!$compose) {
            if ($manager === 'dockerman') $out .= "<td><input type='checkbox' id='$id-auto' class='autostart' container='" . htmlspecialchars($name) . "'" . ($ct['autostart'] ? ' checked' : '') . ">";
            else $out .= "<td><i class='fa fa-docker fa-fw'/></i> 3rd Party";
        } else $out .= "<td><i class='fa fa-docker'/></i> Compose";
        $out .= "<span id='$id-wait' style='float:right;display:none'>wait<input class='wait' container='" . htmlspecialchars($name) . "' type='number' value='' placeholder='0' title=\"seconds\"></span></td>";
        $out .= "<td><div style='white-space:nowrap'>" . ($running ? 'Uptime: 3 hours' : 'Exited (0) 2 days ago') . "<div style='margin-top:4px'>Created: 2 days ago</div></div></td></tr>";
    }
    foreach (svtb_world()['orphans'] as $img) {
        $id = $img['id'];
        $menu = sprintf("onclick=\"addDockerImageContext('%s','%s')\"", $id, $img['tags']);
        $out .= "<tr class='advanced'><td style='width:220px;padding:8px'>";
        $out .= "<span class='outer apps'><span id='$id' $menu class='hand'><img src='/webGui/images/disk.png' class='img'></span><span class='inner'>(orphan image)<br><i class='fa fa-square stopped red-text'></i><span class='state'>stopped</span></span></span>";
        $out .= "</td><td colspan='8'>Image ID: $id<br>" . $img['tags'] . "</td><td>Created 3 weeks ago</td></tr>";
    }
    if (!$out) $out = "<tr><td colspan='10' style='text-align:center;padding-top:12px'>No Docker containers installed</td></tr>";
    return $out . "\0" . implode('', $push) . "\0" . '0';
}

#!/bin/sh

echo "Setting up Pisowifi from repository..."

# Define project root (current directory)
PROJECT_ROOT=$(pwd)

# 1. Install dependencies
echo "Installing required packages..."
opkg update
opkg install iptables-nft iptables-mod-nat-extra iptables-mod-conntrack-extra php8-cli php8-cgi php8-mod-session

# 2. Link System Files
echo "Linking system configuration files..."

# Init script
if [ -f "$PROJECT_ROOT/system/init.d/pisowifi" ]; then
    rm -f /etc/init.d/pisowifi
    ln -s "$PROJECT_ROOT/system/init.d/pisowifi" /etc/init.d/pisowifi
    chmod +x /etc/init.d/pisowifi
fi

# Config file
if [ -f "$PROJECT_ROOT/system/config/pisowifi" ]; then
    # We copy config instead of linking to avoid UCI issues on some versions, but linking is better for updates
    # Let's copy for config to be safe with UCI commit
    if [ ! -f /etc/config/pisowifi ]; then
        cp "$PROJECT_ROOT/system/config/pisowifi" /etc/config/pisowifi
    fi
fi

# Backend script
if [ -f "$PROJECT_ROOT/bin/pisowifi-backend.php" ]; then
    rm -f /usr/bin/pisowifi-backend.php
    ln -s "$PROJECT_ROOT/bin/pisowifi-backend.php" /usr/bin/pisowifi-backend.php
    chmod +x /usr/bin/pisowifi-backend.php
fi

# LuCI Controller
mkdir -p /usr/lib/lua/luci/controller
if [ -f "$PROJECT_ROOT/system/luci/controller/pisowifi.lua" ]; then
    rm -f /usr/lib/lua/luci/controller/pisowifi.lua
    ln -s "$PROJECT_ROOT/system/luci/controller/pisowifi.lua" /usr/lib/lua/luci/controller/pisowifi.lua
fi

# LuCI View
mkdir -p /usr/lib/lua/luci/view/pisowifi
if [ -f "$PROJECT_ROOT/system/luci/view/pisowifi/admin.htm" ]; then
    rm -f /usr/lib/lua/luci/view/pisowifi/admin.htm
    ln -s "$PROJECT_ROOT/system/luci/view/pisowifi/admin.htm" /usr/lib/lua/luci/view/pisowifi/admin.htm
fi

# 3. Configure uhttpd
echo "Configuring web server..."
uci set uhttpd.main.index_page='index.php'
uci -q del_list uhttpd.main.interpreter='.php=/usr/bin/php-cgi'
uci add_list uhttpd.main.interpreter='.php=/usr/bin/php-cgi'
# Point uhttpd home to this directory if we want to serve directly from here?
# Usually /www is the home. If we cloned into /www/pisowifi, we need to access via /pisowifi/
# But if we want it to be the ROOT, we might need to change home or symlink /www/index.php
# For now, we assume user cloned to /www/pisowifi
# We create a redirect in /www/index.php if it doesn't exist
if [ ! -f /www/index.php ] || grep -q "openwrt.org" /www/index.php; then
    cat << 'EOF' > /www/index.php
<?php
header("Location: http://10.0.0.1/pisowifi/");
exit;
?>
EOF
fi

uci commit uhttpd
/etc/init.d/uhttpd restart

# 4. Start Services
echo "Starting Pisowifi services..."
/etc/init.d/pisowifi enable
/etc/init.d/pisowifi start

echo "Setup complete!"

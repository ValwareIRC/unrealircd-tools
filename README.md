# unrealircd-tools
## UnrealIRCd Configuration Parser in PHP
This is a simple PHP script that parses `*.conf` files to do with UnrealIRCd. Nice if you're making something and need to check the config.

## Deluxe UnrealIRCd Installer (ubuntu/debian)
This is the fastest way to set up an UnrealIRCd server from scratch.

First, make the file an executable:
```
chmod +x unrealircd_installer
```
Then you can run it using `./unrealircd_installer`

This will ask you a few configuration-related questions, and then it will download UnrealIRCd, install it and configure it using your provided information.

When it's done, you will be offered if you want to start UnrealIRCd already, and you can because the configuration is set up thanks to the installer.

This leaves behind an `unrealircd_installer.settings` file which will speed up an installation process if you need to use it again.

### Parameters
`prereq` - Installs the system prerequisites (requires sudo) (DO NOT RUN AS ROOT USER)
`nocheck` - Installs the latest UnrealIRCd without confirmation
`addalias` - Adds an \"unrealircd\" alias to the current users environment, so you can `unrealircd rehash` from anywhere :D
`runwhendone` - Runs UnrealIRCd after it's installed without confirmation


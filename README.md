# Tank Wars Retro

Tank Wars Retro is a browser artillery game inspired by classic VGA-era tank games. It runs with no build step and supports local hot-seat battles, CPU crews, and multiplayer rooms backed by a small PHP service.

Live repository: <https://github.com/zenwalkr/Tank-Wars-Retro>

## Features

- Classic 640×400-style presentation rendered from a responsive HTML interface.
- Local battles for 2–10 total tanks, including human and CPU slots.
- Seven CPU personalities: Random, Mr. Stupid, Lobber, Rifleman, Lob & Shoot, Windless Wit, and Twanger.
- Online rooms with public listings, private six-character room codes, host controls, and browser polling.
- Free-for-all, humans-versus-CPU, and alternating red-versus-blue teams.
- 1–99 rounds, persistent campaign credits, an armory between rounds, round wins, kills, and rematches.
- Destructible terrain, craters, dirt, landslides, wind, gravity, air drag, field-wall behavior, and optional nuclear meltdowns.
- Eighteen weapons, two guidance systems, three defenses, and four field items.
- Sound effects, shot trails, aim-assist dots/history, keyboard controls, touch controls, and responsive portrait rotation.

## Requirements

For local play, any current desktop or mobile browser is enough.

For online play, host the files on a PHP-enabled web server with:

- PHP 8.0 or newer recommended.
- PHP sessions enabled.
- SQLite3 enabled (recommended).
- A writable directory containing `tanks-rooms.php` so the service can create `tanks_rooms.sqlite`.
- HTTPS recommended when the game is served publicly.

If SQLite3 is unavailable, the room service falls back to `tanks_rooms.json`. The directory must still be writable. SQLite is preferred because it uses an immediate transaction for state-changing actions, preventing simultaneous online shots from consuming the same turn.

## Quick start

### Local development

From the project directory, run:

```bash
php -S 127.0.0.1:8080
```

Open <http://127.0.0.1:8080/index.html>.

The PHP endpoint is only needed for online rooms. Local games run entirely in the browser, so opening `index.html` from a static web server is sufficient for local play. Using a web server is still recommended because browser security policies vary for `file://` URLs.

### Production deployment

1. Copy `index.html` and `tanks-rooms.php` into the same public directory.
2. Point the browser at `index.html`.
3. Confirm the web-server user can create and update `tanks_rooms.sqlite` in that directory.
4. Confirm PHP sessions work and that `tanks-rooms.php?api=list` returns JSON rather than an HTML error page.
5. Test a private room with two browser windows or devices before opening public rooms.

Do not expose a pre-populated development database. The runtime database is intentionally ignored by Git; the service creates its schema on first use.

## Starting a local game

1. Select **START** from the main menu.
2. In the player setup screen, click each slot to cycle it through off, human, and computer. You need at least two active tanks and at least one human commander.
3. Open **GAME OPTIONS** to configure commander names, CPU count/personality, rounds, starting credits, terrain, teams, wind, gravity, air viscosity, landslides, walls, and destroyed-tank behavior.
4. Click **START** or **Deploy Tanks**.
5. Each human commander uses the same device in turn. The active commander is shown in the top HUD.

Human names are entered as a comma-separated list. Preferences and the commander name are saved in browser `localStorage`.

## Hosting or joining online rooms

### Host

1. Choose **ONLINE ROOMS**, then **HOST NEW**.
2. Enter a room name and commander name.
3. Choose the maximum number of human commanders, CPU tanks, and battle settings.
4. Choose **PUBLIC / LISTED** to show the room in the public list, or **PRIVATE / CODE ONLY** to require the six-character code.
5. Select **Create Room** and share the displayed code with other commanders.
6. The host chooses **Launch Battle** after the desired players have joined.

The host is responsible for advancing CPU turns and for opening the armory or launching the next round. If the host leaves, the first remaining human commander becomes host.

### Join

1. Choose **ONLINE ROOMS**.
2. Enter a commander name.
3. Select a listed room or enter its six-character code and choose **Join Room**.
4. Wait in the lobby for the host to launch the battle.

Online state is polled approximately every 900 ms. A commander is considered disconnected after about 25 seconds without a state request. Empty rooms are removed after their last human leaves; stale-room cleanup also runs when the room list is requested.

## How to play

### Aiming and firing

- Set the cannon angle from 5° to 175°. Angles above 90° fire toward the left.
- Set power from 100 to 1000.
- Choose a weapon, optional guidance, and an enemy target.
- Press **FIRE** or the Space key.
- Arrow Left/Right adjusts angle; Arrow Up/Down adjusts power. The step sizes are configurable under **Sound Options**.
- Press Tab to cycle available weapons.

Wind, gravity, drag, terrain, and wall settings affect ballistic shots. A laser is instantaneous and ignores wind. A shot can hit a tank, deform terrain, trigger falling damage, or leave the field.

### Rounds and campaign economy

The round ends when only one side remains. Every crew receives a round stipend, and the surviving side receives a victory bonus. Damage and eliminations also earn credits. Between rounds, commanders visit the armory; unused stock carries through the match. The standard missile is unlimited.

At the end of the configured number of rounds, the commander with the most wins is the campaign champion. A host can start a rematch from the campaign-complete screen.

## Arsenal

### Weapons

| Weapon | Behavior |
| --- | --- |
| Small Missile | Unlimited standard explosive round. |
| Tracer Shell | Small blast with a long-lived trail. |
| Heavy Missile | Larger crater and stronger blast. |
| Armor Piercer | Small radius and high direct-hit damage. |
| Baby Nuke | Compact nuclear blast. |
| Atomic Nuke | Large blast capable of hitting several tanks. |
| Hydrogen Nuke | Extreme blast and terrain damage. |
| Cluster Bomb | Five bomblets across the impact area. |
| MIRV | Five separated warheads. |
| Triple Spread | Three nearby missiles at adjacent angles. |
| Roller | Rolls downhill before detonating. |
| Burrower | Explodes below the surface. |
| Napalm | Scatters several burning impact zones. |
| Riot Bomb | Removes terrain without direct tank damage. |
| Dirt Ball | Raises terrain and can protect or bury a tank. |
| Laser | Instant straight beam, unaffected by wind. |
| Jackhammer | Drives deep underground before a focused blast. |
| Volcano Bomb | Creates five widely spaced secondary blasts. |

### Guidance, defenses, and items

- **Ballistic Computer** calculates a shot angle and power for a selected target.
- **Vertical Guidance** steers a descending projectile toward a selected target.
- **Armor Plating** permanently adds 25 maximum hull points, up to three levels.
- **Energy Shield** adds temporary damage absorption.
- **Reactive Armor** automatically halves the next explosion that hits.
- **Field Repair** restores hull points and consumes the turn.
- **Teleport** moves the tank to a random position.
- **Parachute** automatically prevents dangerous falling damage.
- **Wind Fan** reverses and strengthens the wind for the next crew.

## Online API

The client uses `tanks-rooms.php` in the same directory:

| Request | Purpose |
| --- | --- |
| `GET ?api=catalog` | Returns weapon, guidance, defense, and item definitions. |
| `GET ?api=list` | Lists public rooms. |
| `GET ?api=state&code=XXXXXX` | Returns the caller's current room state. |
| `POST action=create` | Creates a room and returns its code. |
| `POST action=join` | Joins a lobby by code. |
| `POST action=leave` | Leaves a room. |
| `POST action=update_settings` | Updates lobby settings for the host. |
| `POST action=start_game` | Adds configured CPU tanks and starts the first shop phase. |
| `POST action=aim` | Stores the current angle and power for the active commander. |
| `POST action=fire` / `cpu_fire` | Applies an authoritative shot. |
| `POST action=use_item` / `cpu_item` | Applies a field item. |
| `POST action=pass` | Ends the current turn without firing. |
| `POST action=open_shop` | Moves a completed round into the armory. |
| `POST action=shop_commit` / `ready` | Commits a loadout or marks a commander ready. |
| `POST action=launch_round` | Starts the next round after connected commanders are ready. |
| `POST action=rematch` | Resets the match and starts a new campaign. |

All state-changing actions validate the active commander, room phase, turn, inventory, and host permissions on the server. Room state is returned as JSON and browser sessions identify commanders.

## Files

- `index.html` — Complete client application, styling, canvas battle renderer, local rules, online client, sound, and responsive VGA presentation.
- `tanks-rooms.php` — PHP room service, game-state validation, projectile simulation, economy, CPU turns, and SQLite/JSON persistence.
- `fork-320x200fb` — Earlier self-contained 320×200/VGA framebuffer experiment retained as a reference build.
- `tanks_rooms.sqlite` — Local runtime database; ignored and should not be committed.

## Troubleshooting

**The page loads but online rooms fail.** Make sure `tanks-rooms.php` is reachable from the same origin as `index.html`, PHP is enabled, sessions are working, and the directory is writable.

**SQLite errors appear.** Enable the PHP SQLite3 extension or allow the JSON fallback by making the directory writable. SQLite is safer for simultaneous online actions.

**A room disappears.** Rooms are pruned when empty, after 30 minutes without activity, or when a human commander has timed out. Recreate the room and share the new code.

**A browser window appears stuck.** Refresh the page, rejoin the room, and verify that the host has not left. Online clients intentionally wait for authoritative server state before accepting the next action.

**The game is too small or rotated.** The game is designed around a 640×400 classic layout. Resize the browser or rotate a phone; portrait mode rotates the playfield automatically.

## License

No license has been selected yet. Add a license file before distributing the project beyond personal use.

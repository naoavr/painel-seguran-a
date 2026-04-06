# Monitor Central

**Monitor Central** é um painel de segurança centralizado escrito em PHP para monitorização em tempo real de múltiplos websites. Agrega tráfego, erros PHP, alterações de ficheiros, reputação de IPs e inteligência de ameaças num único dashboard seguro.

---

## Índice

- [Características](#características)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Integrações](#integrações)
- [Painel de Controlo](#painel-de-controlo)
- [Referência de Funções](#referência-de-funções)
- [Segurança](#segurança)

---

## Características

| Módulo | Descrição |
|---|---|
| **Dashboard** | Visão geral com estatísticas de sites, IPs bloqueados, erros PHP, alterações de ficheiros, malware ativo e alertas |
| **Sites Status** | Monitorização de uptime, estado HTTP e validade/expiração do certificado SSL |
| **Traffic Log** | Registo completo de pedidos: IP, URL, método, status, user-agent, referer e tempo de resposta |
| **Statistics** | Gráficos horários/diários de tráfego, erros e bloqueios |
| **IP Relations** | Mapa de relações entre IPs e sites monitorizados |
| **IP Intelligence** | Consulta de reputação via AbuseIPDB, geolocalização via ip-api.com, score de abuso, ISP e país |
| **Error Log** | Captura e exibe erros PHP fatais (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR) reportados pelos agentes |
| **File Monitor** | Deteta ficheiros adicionados, modificados e removidos; scan de malware automático em ficheiros alterados |
| **Threat Intel** | Gestão de feeds de inteligência de ameaças (IP blocklists, domain blocklists, malware hashes) |
| **Site Management** | Adicionar/remover sites, geração automática de API keys |
| **Settings** | Configuração global: email de alertas, tentativas máximas de login, chaves de API externas |
| **Cron Jobs** | Estado e histórico de tarefas agendadas (atualização de feeds, verificação SSL, limpeza de dados) |
| **Database** | Consulta e gestão direta da base de dados pelo painel |
| **Globe View** | Visualização 3D interativa de tráfego mundial em tempo real |
| **Manual** | Documentação integrada no painel |

### Funcionalidades de Segurança

- Autenticação com sessões seguras (HttpOnly, SameSite=Strict, Secure)
- Proteção CSRF em todos os formulários
- Rate limiting de tentativas de login (por IP, janela de 60 s)
- Bloqueio de IPs via `.htaccess` (gerado automaticamente)
- Scan de malware em ficheiros PHP com deteção de padrões (webshells, eval+base64, obfuscação, etc.)
- Alertas categorizados por severidade (info, warning, critical)

---

## Requisitos

- **PHP** 8.0 ou superior
- **MySQL** 5.7+ ou **MariaDB** 10.2+
- Extensões PHP: `pdo_mysql`, `curl`, `openssl`, `json`
- Servidor web: Apache (com `mod_rewrite`) ou Nginx

---

## Instalação

### 1. Fazer upload dos ficheiros

Copie todos os ficheiros do repositório para o diretório público do servidor (ex.: `/var/www/html/monitor`).

### 2. Permissões

O diretório raiz deve ter permissão de escrita para que o wizard possa criar o `config.php`:

```bash
chmod 755 /var/www/html/monitor
```

### 3. Executar o Wizard de Instalação

Aceda a `https://your-domain.com/monitor/install.php` no browser. O wizard guia-o por 6 passos:

| Passo | Descrição |
|---|---|
| 1 — Requirements | Verificação automática de requisitos (PHP, extensões, permissões) |
| 2 — Database | Introduza host, nome da base de dados, utilizador e palavra-passe MySQL |
| 3 — Schema | Criação automática de todas as tabelas a partir de `sql/schema.sql` |
| 4 — Admin User | Criação do utilizador administrador (mínimo 8 caracteres na password) |
| 5 — Config | Geração e escrita automática do ficheiro `config.php` com secret aleatório |
| 6 — Complete | Instalação concluída |

### 4. Eliminar o ficheiro de instalação

> ⚠️ **Obrigatório por segurança.** Após a instalação, elimine o ficheiro `install.php`:

```bash
rm /var/www/html/monitor/install.php
```

### 5. Aceder ao painel

Navegue até `https://your-domain.com/monitor/` e faça login com o utilizador criado no passo 4.

---

## Configuração

O ficheiro `config.php` é criado automaticamente pelo wizard. Para edição manual:

```php
define('DB_HOST',           'localhost');
define('DB_NAME',           'iddigital_monitor');
define('DB_USER',           'your_db_user');
define('DB_PASS',           'your_db_password');
define('DB_CHARSET',        'utf8mb4');
define('APP_URL',           'https://your-domain.com/monitor');
define('APP_SECRET',        'change-this-to-random-string-at-least-32-chars');
define('SESSION_LIFETIME',  3600);
define('ABUSEIPDB_API_KEY', '');   // opcional: https://www.abuseipdb.com/
define('IPAPI_KEY',         '');   // opcional: https://ipapi.com/
```

---

## Integrações

### Agente PHP Genérico

Inclua o agente em qualquer site PHP para começar a enviar dados para o Monitor Central:

```php
define('MC_API_URL', 'https://your-monitor.com/ingest.php');
define('MC_API_KEY', 'your-api-key-here');
require_once 'agent.php';
```

O agente (`agents/agent.php`) captura automaticamente tráfego, erros fatais e envia heartbeats a cada 5 minutos.

### Plugin WordPress

Coloque o ficheiro `integrations/wordpress/monitor-central.php` na pasta `wp-content/plugins/monitor-central/` e ative o plugin. Configure em **Definições → Monitor Central**:

- **API URL**: `https://your-monitor.com/ingest.php`
- **API Key**: a chave gerada no painel

### Módulo PrestaShop

Copie `integrations/prestashop/MonitorCentral.php` para `modules/monitorcentral/MonitorCentral.php`. Instale e configure em **Módulos → Monitor Central**:

- **API URL** e **API Key** nos campos de configuração do módulo

---

## Painel de Controlo

A navegação lateral está dividida em três secções:

**Monitoring**
- 📊 Dashboard · 🌐 Sites Status · 🌊 Traffic Log · 📈 Statistics · 🔗 IP Relations

**Security**
- 🛡 IP Intelligence · ⚠️ Error Log · 📁 File Monitor · 🌐 Threat Intel

**Admin**
- ⚙️ Sites · 🔧 Settings · ⏰ Cron Jobs · 🗄 Database · 📖 Manual · 🌍 Globe View

---

## Referência de Funções

### `includes/functions.php`

| Função | Assinatura | Descrição |
|---|---|---|
| `format_bytes` | `(int $bytes, int $precision = 2): string` | Converte bytes para representação legível (B, KB, MB, GB, TB) |
| `time_ago` | `(string $datetime): string` | Converte um datetime para tempo relativo ("2 hours ago", "3 days ago") |
| `get_flag_emoji` | `(string $country_code): string` | Devolve o emoji de bandeira correspondente ao código de país ISO 3166-1 |
| `truncate` | `(string $str, int $len = 50): string` | Trunca uma string para o comprimento máximo indicado |
| `sanitize_input` | `(mixed $input): string` | Remove tags HTML, espaços e codifica caracteres especiais |
| `generate_api_key` | `(): string` | Gera uma API key aleatória de 64 caracteres hexadecimais |
| `check_abuseipdb` | `(string $ip): ?array` | Consulta a API AbuseIPDB e guarda o resultado na tabela `ip_reputation` |
| `get_ip_info` | `(string $ip): ?array` | Obtém geolocalização (país, cidade, ISP) via ip-api.com |
| `check_ssl` | `(string $domain): array` | Verifica a validade e data de expiração do certificado SSL de um domínio |
| `update_htaccess_block` | `(string $ip): bool` | Adiciona um IP ao bloco de negação no ficheiro `.htaccess` |
| `malware_scan_file` | `(string $content, string $file_path): ?array` | Analisa o conteúdo de um ficheiro PHP à procura de padrões de malware (webshells, eval+base64, obfuscação, etc.) |
| `send_alert` | `(int $site_id, string $type, string $severity, string $message, array $data = []): bool` | Cria um alerta na tabela `alerts` com o tipo e severidade indicados |

### `includes/auth.php`

| Função | Assinatura | Descrição |
|---|---|---|
| `auth_session_start` | `(): void` | Inicia a sessão PHP com parâmetros seguros (HttpOnly, SameSite, Secure) |
| `is_logged_in` | `(): bool` | Verifica se existe uma sessão válida e não expirada na base de dados |
| `require_auth` | `(): void` | Redireciona para `index.php` se o utilizador não estiver autenticado |
| `login_user` | `(int $user_id, string $ip, string $user_agent): bool` | Cria uma sessão autenticada, regenera o session ID e define token CSRF |
| `logout_user` | `(): void` | Remove a sessão da base de dados, limpa cookies e destrói a sessão PHP |
| `get_current_user_data` | `(): ?array` | Devolve os dados do utilizador autenticado (id, username, email, last_login) |
| `check_rate_limit` | `(string $ip): bool` | Verifica se o IP ultrapassou o limite de tentativas de login (janela de 60 s) |
| `record_login_attempt` | `(string $ip): void` | Regista uma tentativa de login falhada para o IP indicado |

### `agents/agent.php` — Classe `MonitorCentralAgent`

| Método | Descrição |
|---|---|
| `getInstance()` | Singleton — devolve a instância única do agente |
| `init()` | Regista o shutdown handler e o error handler; inicia o timer |
| `captureTraffic()` | Captura os dados do pedido HTTP atual (IP, URL, método, user-agent) |
| `errorHandler(...)` | Captura erros fatais PHP e adiciona-os ao buffer |
| `shutdown()` | Envia tráfego, erros e heartbeat para o servidor do Monitor Central |
| `checkWatchList()` | Consulta a lista de ficheiros monitorizados e deteta alterações por hash MD5 |

---

## Segurança

- Elimine `install.php` após a instalação.
- Defina um `APP_SECRET` longo e aleatório em `config.php`.
- Configure HTTPS no servidor web e certifique-se de que o cookie de sessão é enviado com `Secure`.
- Restrinja o acesso à pasta do painel por IP no servidor web se possível.
- Atualize as API keys (AbuseIPDB, IPAPI) nas definições do painel para ativar a inteligência de IPs.

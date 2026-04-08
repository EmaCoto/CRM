# CRM Local Setup With Docker

Repositorio:
`https://github.com/EmaCoto/CRM`

Esta guía explica qué debe hacer una persona que clone el proyecto para poder usarlo en su propio PC con Docker.

## 1. Qué tiene que instalar primero

Para usar este proyecto con Docker, no necesita instalar PHP, Composer, MySQL ni Apache/Nginx en su máquina.

Sí necesita instalar:

- `Git`
- `Docker Desktop`
- `WSL2` si está en Windows

Recomendado en Windows:

1. Instalar `WSL2`
2. Instalar `Docker Desktop`
3. Activar integración de Docker con WSL
4. Instalar `Git`

## 2. Requisitos de Laravel para este proyecto

Aunque este proyecto corre con Docker, internamente usa:

- `PHP 8.3`
- `Composer 2.5+`
- `MySQL 8.0+`
- `Laravel 12`

Eso ya queda cubierto por los contenedores Docker del proyecto.

## 3. Clonar el repositorio

```bash
git clone https://github.com/EmaCoto/CRM.git
cd CRM
```

## 4. Crear el archivo `.env`

En Linux/macOS:

```bash
cp .env.docker.example .env
```

En Windows PowerShell:

```powershell
Copy-Item .env.docker.example .env
```

## 5. Instalar dependencias PHP

Este paso instala `vendor/` dentro del proyecto usando Docker:

```bash
docker compose run --rm composer install
```

## 6. Levantar los contenedores

```bash
docker compose up -d --build
```

Esto levanta:

- Laravel (`app`)
- Nginx (`web`)
- MySQL (`mysql`)
- Redis (`redis`)
- Mailpit (`mailpit`)
- phpMyAdmin (`phpmyadmin`)

## 7. Ejecutar la instalación de Krayin

Con el `.env` de Docker ya configurado, lo correcto es correr:

```bash
docker compose exec app php artisan krayin-crm:install --skip-env-check
```

Ese comando:

- genera la `APP_KEY`
- crea las tablas
- carga seeders
- publica archivos necesarios
- crea el usuario administrador

## 8. Datos del usuario administrador

Cuando el instalador pida los datos del admin, usar:

- email: `admin@example.com`
- password: `admin123`

Si el instalador pregunta por el nombre, se puede usar por ejemplo:

- name: `Admin`

## 9. URLs del proyecto

Una vez levantado:

- CRM: `http://localhost:8080`
- Login admin: `http://localhost:8080/admin/login`
- phpMyAdmin: `http://localhost:8081`
- Mailpit: `http://localhost:8025`

## 10. Acceso a phpMyAdmin

Usar estas credenciales:

- servidor: `mysql`
- usuario: `root`
- contraseña: `root`

## 11. Comandos útiles

Ver contenedores:

```bash
docker compose ps
```

Ver logs:

```bash
docker compose logs -f
```

Ejecutar migraciones:

```bash
docker compose exec app php artisan migrate
```

Entrar al contenedor de Laravel:

```bash
docker compose exec app bash
```

Detener todo:

```bash
docker compose down
```

Borrar contenedores y volúmenes:

```bash
docker compose down -v
```

## 12. Si algo falla

Probar este flujo limpio:

```bash
docker compose down -v
docker compose run --rm composer install
docker compose up -d --build
docker compose exec app php artisan krayin-crm:install --skip-env-check
```

## 13. Resumen rápido

```bash
git clone https://github.com/EmaCoto/CRM.git
cd CRM
cp .env.docker.example .env
docker compose run --rm composer install
docker compose up -d --build
docker compose exec app php artisan krayin-crm:install --skip-env-check
```

Login final:

- usuario: `admin@example.com`
- contraseña: `admin123`

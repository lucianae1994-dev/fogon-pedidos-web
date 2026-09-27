# Fogon Pedidos - Backend web

Backend PHP + portal de pedidos de El Fogon. Esto es lo que se despliega en
Hostinger, en `public_html/pedidos/`.

## Archivos

- `config.example.php` - plantilla de configuracion. En el servidor se copia
  una vez como `config.php` (sin ".example") con los datos reales. `config.php`
  NUNCA se sube al repositorio (ver `.gitignore`).
- `admin.php` - API que usa el programa de escritorio (requiere `X-Admin-Key`).
- `api.php` - API que usa el portal publico (login por codigo de acceso).
- `index.html`, `app.js`, `style.css` - portal web que ven los clientes.
- `schema.sql` - estructura de las tablas (solo de referencia; ya estan
  creadas en el servidor, no hace falta volver a correrlo).

## Primer despliegue en un servidor nuevo

1. Crear la base de datos en MySQL y correr `schema.sql` una vez (por
   phpMyAdmin, por ejemplo).
2. Copiar `config.example.php` como `config.php` y completar `$DB_HOST`,
   `$DB_NAME`, `$DB_USER`, `$DB_PASS` y `ADMIN_KEY` con los datos reales.
3. Verificar que el programa de escritorio tenga la misma `ADMIN_KEY` en su
   configuracion.

## Despliegues siguientes (con Git ya conectado en Hostinger)

Con Git conectado en hPanel (Sitio web > Git), cada `git push` a la rama
configurada actualiza los archivos en el servidor. `config.php` y la carpeta
`fotos/` (fotos de productos subidas por FTP) quedan afuera del repositorio,
asi que un despliegue no los toca ni los borra.

## Importante: repositorio privado

`admin.php`/`api.php` no tienen secretos hardcodeados (viven en `config.php`,
que no se sube), pero de todas formas conviene mantener este repositorio
como **privado** en GitHub.
